<?php

namespace App\Service;

use App\Entity\Project;
use App\Entity\TestRun;
use App\Repository\TestRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A game's PHPUnit tests, run in the sandbox of the tester service (docker/tester: no network, no capability, an
 * unprivileged user, a time and memory limit), which only sees the tests folder (a volume of its own):
 *   work/<id>/          the published game, and the engine's PHP code (its jumphp-vendor.pack) in vendor/
 *   jobs/<id>.json      a run to do; the tester renames it <id>.running while it runs
 *   results/<id>.json   what it found: status, output, summary
 *   films/<id>/         the game tests filmed as they run (index.json, <n>/<frame>.png): replayed in the studio
 * The game tests (JumPHP\Testing\GameTestCase) play the game in the sandbox (SDL without a window); an engine
 * older than JumPHP\Testing runs them with the newest engine installed.
 */
final class TestRunner
{
    /** a run not started after this (the tester is down), or not finished after this, is a failure */
    private const STALE = 300;
    /** the films of the last runs of a game are kept */
    private const FILMS_KEPT = 5;

    private Filesystem $fs;

    public function __construct(
        private string $testsDir,
        private GameFiles $files,
        private Runtimes $runtimes,
        private TestRunRepository $runs,
        private EntityManagerInterface $em,
    ) {
        $this->fs = new Filesystem();
    }

    /** the game has PHPUnit tests: tests/…Test.php */
    public function hasTests(Project $project): bool
    {
        foreach (array_keys($this->files->tree($project)) as $path) {
            if (str_starts_with($path, 'tests/') && str_ends_with($path, 'Test.php')) {
                return true;
            }
        }
        return false;
    }

    public function start(Project $project): TestRun
    {
        $game = $this->files->dir($project);
        if (!$game || !$project->getEngineVersion()) {
            throw new ProjectException('Le jeu n\'est pas encore publié.');
        }
        if (!$this->hasTests($project)) {
            throw new ProjectException('Ce jeu n\'a pas de tests : ajoutez des fichiers tests/…Test.php (PHPUnit), poussez, puis relancez.');
        }
        if ($pending = $this->runs->pending($project)) {
            $this->refresh($pending);
            if (!$pending->isDone()) {
                return $pending;
            }
        }
        // the folders are the playground's own (0755): the tests, run as nobody, cannot write there
        $this->fs->mkdir(["$this->testsDir/jobs", "$this->testsDir/work", "$this->testsDir/results", "$this->testsDir/engines", "$this->testsDir/films"], 0755);
        $run = new TestRun($project, (string)$project->getPublishedSha());
        $this->em->persist($run);
        $this->em->flush();
        try {
            $work = "$this->testsDir/work/{$run->getId()}";
            $this->fs->mirror("$game/game", $work);
            $this->fs->remove(["$work/vendor", "$work/var", "$work/site"]);
            $version = (string)$project->getEngineVersion();
            $engine = $this->engine($version);
            // the game tests (JumPHP\Testing\GameTestCase) need an engine that has them
            if (!is_file("$engine/jumphp/engine/src/Testing/GameTestCase.php") && $this->hasGameTests("$work/tests")) {
                foreach (array_reverse($this->runtimes->installed()) as $newer) {
                    if (version_compare($newer, $version, '>') && is_file($this->engine($newer) . '/jumphp/engine/src/Testing/GameTestCase.php')) {
                        $engine = $this->engine($newer);
                        $this->fs->dumpFile("$work.note", "[i] Tests de jeu lancés avec le moteur JumPHP $newer (le jeu est en $version, sans JumPHP\Testing).");
                        break;
                    }
                }
            }
            $this->fs->mirror($engine, "$work/vendor");
            $this->fs->dumpFile("$work/vendor/autoload.php", $this->autoload("$work/composer.json"));
            $this->fs->dumpFile("$this->testsDir/jobs/{$run->getId()}.json", json_encode(['id' => $run->getId(), 'project' => $project->getSlug()]));
        } catch (\Throwable $e) {
            $this->fs->remove("$this->testsDir/work/{$run->getId()}");
            $run->finish(TestRun::ERROR, 'Préparation impossible : ' . $e->getMessage());
            $this->em->flush();
        }
        $this->runs->prune($project);
        foreach ($this->runs->latest($project, 30) as $i => $old) {
            if ($i >= self::FILMS_KEPT) {
                $this->fs->remove("$this->testsDir/films/{$old->getId()}");
            }
        }
        return $run;
    }

    /** a tests folder has game tests (they extend GameTestCase) */
    private function hasGameTests(string $dir): bool
    {
        foreach (is_dir($dir) ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) : [] as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php') && str_contains((string)file_get_contents($file->getPathname()), 'GameTestCase')) {
                return true;
            }
        }
        return false;
    }

    /**
     * The game tests of a run, filmed: each test (in the order they ran) with its name and its frames' URLs path
     * (films/<id>/<n>/<frame>.png, served by film()). Grows while the run runs.
     *
     * @return list<array{n: string, name: string, frames: int}>
     */
    public function films(TestRun $run): array
    {
        $index = json_decode((string)@file_get_contents("$this->testsDir/films/{$run->getId()}/index.json"), true);
        $films = [];
        foreach ((array)($index['tests'] ?? []) as $test) {
            if (preg_match('/^\d{3}$/', (string)($test['n'] ?? '')) && ($test['frames'] ?? 0) > 0) {
                $films[] = ['n' => (string)$test['n'], 'name' => (string)($test['name'] ?? ''), 'frames' => (int)$test['frames']];
            }
        }
        return $films;
    }

    /** a frame of a run's film: its file, or null */
    public function frame(TestRun $run, string $n, string $frame): ?string
    {
        if (!preg_match('/^\d{3}$/', $n) || !preg_match('/^\d{4}$/', $frame)) {
            return null;
        }
        $file = "$this->testsDir/films/{$run->getId()}/$n/$frame.png";
        return is_file($file) ? $file : null;
    }

    /** What the tester found, if it is done; a run that waits too long fails. */
    public function refresh(TestRun $run): TestRun
    {
        if ($run->isDone()) {
            return $run;
        }
        $id = $run->getId();
        $result = "$this->testsDir/results/$id.json";
        if (is_file($result)) {
            $data = json_decode((string)file_get_contents($result), true) ?: [];
            $status = in_array($data['status'] ?? null, [TestRun::PASSED, TestRun::FAILED, TestRun::ERROR], true) ? $data['status'] : TestRun::ERROR;
            $note = (string)@file_get_contents("$this->testsDir/work/$id.note");
            $run->finish($status, ($note !== '' ? "$note\n\n" : '') . (string)($data['output'] ?? ''), (array)($data['summary'] ?? []));
            $this->fs->remove([$result, "$this->testsDir/work/$id", "$this->testsDir/work/$id.note"]);
        } elseif (is_file("$this->testsDir/jobs/$id.running")) {
            $run->setRunning();
        }
        if (!$run->isDone() && time() - $run->getCreatedAt()->getTimestamp() > self::STALE) {
            $run->finish(TestRun::ERROR, $run->getStatus() === TestRun::RUNNING
                ? 'Les tests ont duré trop longtemps.' : 'Le banc de test n\'a pas répondu : réessayez plus tard.');
            $this->fs->remove(["$this->testsDir/jobs/$id.json", "$this->testsDir/work/$id", "$this->testsDir/work/$id.note"]);
        }
        $this->em->flush();
        return $run;
    }

    /** the engine's PHP code (its Composer vendor folder), unpacked once from the engine's jumphp-vendor.pack */
    private function engine(string $version): string
    {
        $dir = "$this->testsDir/engines/$version";
        if (is_file("$dir/autoload_engine.php")) {
            return $dir;
        }
        $bytes = (string)@file_get_contents($this->runtimes->dir($version) . '/jumphp-vendor.pack');
        if (!str_starts_with($bytes, 'JPK1')) {
            throw new \RuntimeException("no engine $version");
        }
        // "JPK1", the length of a JSON header (uint32, little endian), {"files": [[path, offset, size], ...]}, the bytes
        $length = unpack('V', substr($bytes, 4, 4))[1];
        $header = json_decode(substr($bytes, 8, $length), true);
        $tmp = "$dir.tmp" . bin2hex(random_bytes(4));
        foreach ($header['files'] as [$path, $offset, $size]) {
            $path = GameFiles::clean((string)$path) ?? throw new \RuntimeException('bad path in the engine pack');
            $this->fs->dumpFile("$tmp/$path", substr($bytes, 8 + $length + $offset, $size));
        }
        $this->fs->remove($dir);
        $this->fs->rename($tmp, $dir);
        return $dir;
    }

    /** the engine's autoloader, plus the game's PSR-4 namespaces (composer.json autoload and autoload-dev) */
    private function autoload(string $composerJson): string
    {
        $composer = json_decode((string)@file_get_contents($composerJson), true) ?: [];
        $psr4 = array_merge(['App\\' => 'src/', 'Tests\\' => 'tests/'], (array)($composer['autoload']['psr-4'] ?? []), (array)($composer['autoload-dev']['psr-4'] ?? []));
        $lines = '';
        foreach ($psr4 as $prefix => $dirs) {
            $dirs = array_map(fn ($d) => trim((string)$d, '/'), (array)$dirs);
            $lines .= '$loader->addPsr4(' . var_export((string)$prefix, true) . ', array_map(fn ($d) => dirname(__DIR__) . "/$d", ' . var_export($dirs, true) . "), true);\n";
        }
        return "<?php\n// the engine (jumphp-vendor.pack) + the game's PSR-4 namespaces (App\\Service\\TestRunner)\n\$loader = require __DIR__ . '/autoload_engine.php';\n{$lines}return \$loader;\n";
    }
}
