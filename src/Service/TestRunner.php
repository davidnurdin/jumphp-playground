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
 */
final class TestRunner
{
    /** a run not started after this (the tester is down), or not finished after this, is a failure */
    private const STALE = 300;

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
        $this->fs->mkdir(["$this->testsDir/jobs", "$this->testsDir/work", "$this->testsDir/results", "$this->testsDir/engines"], 0755);
        $run = new TestRun($project, (string)$project->getPublishedSha());
        $this->em->persist($run);
        $this->em->flush();
        try {
            $work = "$this->testsDir/work/{$run->getId()}";
            $this->fs->mirror("$game/game", $work);
            $this->fs->remove(["$work/vendor", "$work/var", "$work/site"]);
            $this->fs->mirror($this->engine((string)$project->getEngineVersion()), "$work/vendor");
            $this->fs->dumpFile("$work/vendor/autoload.php", $this->autoload("$work/composer.json"));
            $this->fs->dumpFile("$this->testsDir/jobs/{$run->getId()}.json", json_encode(['id' => $run->getId(), 'project' => $project->getSlug()]));
        } catch (\Throwable $e) {
            $this->fs->remove("$this->testsDir/work/{$run->getId()}");
            $run->finish(TestRun::ERROR, 'Préparation impossible : ' . $e->getMessage());
            $this->em->flush();
        }
        $this->runs->prune($project);
        return $run;
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
            $run->finish($status, (string)($data['output'] ?? ''), (array)($data['summary'] ?? []));
            $this->fs->remove([$result, "$this->testsDir/work/$id"]);
        } elseif (is_file("$this->testsDir/jobs/$id.running")) {
            $run->setRunning();
        }
        if (!$run->isDone() && time() - $run->getCreatedAt()->getTimestamp() > self::STALE) {
            $run->finish(TestRun::ERROR, $run->getStatus() === TestRun::RUNNING
                ? 'Les tests ont duré trop longtemps.' : 'Le banc de test n\'a pas répondu : réessayez plus tard.');
            $this->fs->remove(["$this->testsDir/jobs/$id.json", "$this->testsDir/work/$id"]);
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
