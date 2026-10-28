<?php

namespace App\Service;

use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Yaml\Yaml;

/**
 * Publishes a game from its GitHub repository (after a push: webhook, or the creator's sync): a mirror of the
 * repository in var/storage/repos/<id>.git, the game's folder at the branch's last commit extracted to
 * var/storage/published/<id>/<sha>/ (GameFiles), checked (`php -l` only: no code of the game ever runs on the
 * server), its manifest, its web site and its picture. A broken commit is not published: the previous version
 * stays online and the errors are reported.
 */
final class Publisher
{
    private const COPIED_NOT = '#^(vendor|dist|\.build|var|cache|node_modules|\.git)(/|$)#';
    private const MAX_FILES = 4000;
    private const MAX_FILE = 20 * 1024 * 1024;
    private const MAX_TOTAL = 120 * 1024 * 1024;

    private const GAME_DEFAULTS = [
        'id' => 'game', 'title' => 'Game', 'version' => '1.0.0', 'description' => '', 'width' => 320, 'height' => 180,
        'lang' => 'en', 'online' => false, 'net_url' => null, 'touch' => null, 'controls' => null,
    ];

    public function __construct(
        private string $storageDir,
        private string $appUrl,
        private Shell $shell,
        private Runtimes $runtimes,
        private Thumbnailer $thumbnailer,
        private EntityManagerInterface $em,
        private LockFactory $locks,
    ) {
    }

    public function repoDir(Project $project): string
    {
        return "$this->storageDir/repos/{$project->getId()}.git";
    }

    /** The mirror of the repository, up to date. */
    public function fetch(Project $project): string
    {
        $repo = $this->repoDir($project);
        if (!is_dir($repo)) {
            (new Filesystem())->mkdir(dirname($repo));
            $this->shell->run(['git', 'clone', '--mirror', '--quiet', $project->getCloneUrl(), $repo], null, [], 600);
        } else {
            $this->shell->run(['git', 'remote', 'set-url', 'origin', $project->getCloneUrl()], $repo);
            $this->shell->run(['git', 'fetch', '--prune', '--quiet', 'origin'], $repo, [], 600);
        }
        return $repo;
    }

    public function sync(Project $project, bool $force = false): SyncResult
    {
        $lock = $this->locks->createLock('publish-' . $project->getId(), 600);
        $lock->acquire(true);
        try {
            return $this->doSync($project, $force);
        } catch (\Throwable $e) {
            $project->setStatus($project->isPublished() ? Project::STATUS_READY : Project::STATUS_ERROR, $e->getMessage());
            $this->em->flush();
            return new SyncResult(SyncResult::FAILED, null, [$e->getMessage()]);
        } finally {
            $lock->release();
        }
    }

    private function doSync(Project $project, bool $force): SyncResult
    {
        $repo = $this->fetch($project);
        $branch = $project->getDefaultBranch();
        $sha = trim((string)$this->shell->tryRun(['git', 'rev-parse', '--verify', "refs/heads/$branch^{commit}"], $repo));
        if ($sha === '') {
            $head = trim((string)$this->shell->tryRun(['git', 'symbolic-ref', '--short', 'HEAD'], $repo));
            $sha = $head !== '' ? trim((string)$this->shell->tryRun(['git', 'rev-parse', '--verify', "$head^{commit}"], $repo)) : '';
            if ($sha === '') {
                $project->setStatus(Project::STATUS_EMPTY, 'Le dépôt est vide.');
                $this->em->flush();
                return new SyncResult(SyncResult::FAILED, null, ["the repository has no branch $branch yet: push the project"]);
            }
            $project->setDefaultBranch($head);
        }
        if (!$force && $sha === $project->getPublishedSha()) {
            return new SyncResult(SyncResult::UNCHANGED, $sha);
        }

        $gameDir = $this->findGameDir($repo, $sha, $project->getGameDir());
        if ($gameDir === null) {
            return $this->fail($project, $sha, ['no game in the repository: games/<id>/config/game.yaml (or config/game.yaml at the root) is missing']);
        }
        $project->setGameDir($gameDir);

        $fs = new Filesystem();
        $final = "$this->storageDir/published/{$project->getId()}/$sha";
        $work = "$final.tmp-" . bin2hex(random_bytes(4));
        $fs->mkdir($work);
        try {
            [$errors, $warnings, $manifest] = $this->extract($repo, $sha, $gameDir, $work);
            if ($errors) {
                return $this->fail($project, $sha, $errors, $warnings);
            }
            $version = trim((string)$this->shell->tryRun(['git', 'show', "$sha:VERSION"], $repo));
            $engine = $this->runtimes->pick($version);
            if ($engine === null) {
                return $this->fail($project, $sha, ['no JumPHP engine installed on the playground (app:runtime:install)']);
            }
            if ($version !== '' && ltrim($version, 'v') !== $engine) {
                $warnings[] = "the project is JumPHP $version, the playground runs it with the engine $engine";
            }
            $manifest['engine'] = $engine;
            file_put_contents("$work/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            $site = $this->readSite("$work/game/site", $project);
            if ($site === null) {
                $warnings[] = 'no web site for the game: make site/index.html (JumPHP AGENTS.md section 13)';
            }
            $thumb = $this->thumbnailer->make("$work/game", $site['image'] ?? null, "$work/thumb.png");
            if (!$thumb) {
                $warnings[] = 'no picture for the directory: add assets/thumbnail.png or a site with an og:image';
            }

            $fs->remove($final);
            $fs->rename($work, $final);
            $info = array_intersect_key($manifest, self::GAME_DEFAULTS);
            // an imported project is first named after its repository: the game's own title and description then
            if ($project->getTitle() === basename($project->getRepoFullName()) && trim((string)($info['title'] ?? '')) !== '') {
                $project->setTitle(mb_substr(trim((string)$info['title']), 0, 80));
                if (trim((string)($info['description'] ?? '')) !== '') {
                    $project->setDescription(mb_substr(trim((string)$info['description']), 0, 1000));
                }
            }
            $project->publish($sha, $engine, $info, $site, $thumb);
            if ($warnings) {
                $project->setStatus(Project::STATUS_READY, implode("\n", $warnings));
            }
            $this->em->flush();
            $this->prune($project);
            return new SyncResult(SyncResult::PUBLISHED, $sha, [], $warnings, count($manifest['files']));
        } finally {
            $fs->remove($work);
        }
    }

    private function fail(Project $project, string $sha, array $errors, array $warnings = []): SyncResult
    {
        $project->setStatus($project->isPublished() ? Project::STATUS_READY : Project::STATUS_ERROR,
            'Commit ' . substr($sha, 0, 7) . " non publié :\n" . implode("\n", $errors));
        $this->em->flush();
        return new SyncResult(SyncResult::FAILED, $sha, $errors, $warnings);
    }

    /** games/<id> of the project, or the only game of the repository. */
    /** The game's folder in the repository: games/<id>, or "." when the repository is the game folder itself. */
    private function findGameDir(string $repo, string $sha, string $wanted): ?string
    {
        $has = fn (string $dir) => $this->shell->tryRun(['git', 'cat-file', '-e', $sha . ':' . ($dir === '.' ? '' : "$dir/") . 'config/game.yaml'], $repo) !== null;
        if ($has($wanted)) {
            return $wanted;
        }
        $found = [];
        foreach (explode("\n", trim((string)$this->shell->tryRun(['git', 'ls-tree', '--name-only', $sha, 'games/'], $repo))) as $dir) {
            if ($dir !== '' && $this->shell->tryRun(['git', 'cat-file', '-e', "$sha:$dir/config/game.yaml"], $repo) !== null) {
                $found[] = $dir;
            }
        }
        if (count($found) === 1) {
            return $found[0];
        }
        return !$found && $has('.') ? '.' : null;
    }

    /**
     * The game's folder at $sha into $work/game: no symbolic link, no oversized file, PHP syntax checked.
     *
     * @return array{0: string[], 1: string[], 2: array} errors, warnings, manifest
     */
    private function extract(string $repo, string $sha, string $gameDir, string $work): array
    {
        $errors = $warnings = [];
        $this->shell->run(['git', 'archive', '--format=tar', '-o', "$work/game.tar", $sha, '--', $gameDir], $repo);
        if ($gameDir === '.') {
            (new Filesystem())->mkdir("$work/game");
            $this->shell->run(['tar', '-xf', '../game.tar', '--no-same-owner', '--no-same-permissions'], "$work/game");
        } else {
            $this->shell->run(['tar', '-xf', 'game.tar', '--no-same-owner', '--no-same-permissions'], $work);
            (new Filesystem())->rename("$work/$gameDir", "$work/game");
            (new Filesystem())->remove("$work/" . explode('/', $gameDir)[0]);
        }
        unlink("$work/game.tar");

        $root = "$work/game";
        $files = [];
        $total = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $path = substr($file->getPathname(), strlen($root) + 1);
            if ($file->isLink()) {
                unlink($file->getPathname());
                $warnings[] = "$path: symbolic links are not published";
                continue;
            }
            if ($file->isDir()) {
                if (preg_match(self::COPIED_NOT, $path)) {
                    (new Filesystem())->remove($file->getPathname());
                }
                continue;
            }
            if (preg_match(self::COPIED_NOT, $path)) {
                unlink($file->getPathname());
                continue;
            }
            if ($file->getSize() > self::MAX_FILE) {
                $errors[] = "$path: bigger than 20 MB";
                continue;
            }
            $total += $file->getSize();
            $files[$path] = $file->getSize();
        }
        if (count($files) > self::MAX_FILES) {
            $errors[] = 'too many files (' . count($files) . ', at most ' . self::MAX_FILES . ')';
        }
        if ($total > self::MAX_TOTAL) {
            $errors[] = sprintf('the game weighs %.0f MB (at most %d MB)', $total / 1048576, self::MAX_TOTAL / 1048576);
        }

        // game.yaml
        $config = [];
        try {
            $yaml = Yaml::parse((string)@file_get_contents("$root/config/game.yaml"));
            $config = is_array($yaml['game'] ?? null) ? $yaml['game'] : [];
        } catch (\Throwable $e) {
            $errors[] = 'config/game.yaml: ' . $e->getMessage();
        }
        $info = array_intersect_key($config, self::GAME_DEFAULTS) + self::GAME_DEFAULTS;
        $info['id'] = $gameDir !== '.' ? basename($gameDir)
            : (is_string($config['id'] ?? null) && preg_match('/^[a-z0-9-]+$/', $config['id']) ? $config['id'] : 'game');
        $info['width'] = max(16, min(4096, (int)$info['width']));
        $info['height'] = max(16, min(4096, (int)$info['height']));
        foreach (['title', 'description', 'lang', 'version'] as $key) {
            $info[$key] = is_scalar($info[$key]) ? (string)$info[$key] : self::GAME_DEFAULTS[$key];
        }

        // what the browser loads, and the PHP syntax of it
        $loaded = [];
        $php = [];
        foreach ($files as $path => $size) {
            if (preg_match(GameFiles::NOT_LOADED, $path)) {
                continue;
            }
            $loaded[] = ['path' => $path, 'hash' => substr(hash_file('sha256', "$root/$path"), 0, 16), 'size' => $size];
            if (str_ends_with($path, '.php')) {
                $php[] = $path;
            }
        }
        $errors = array_merge($errors, $this->lint($root, $php));

        $composer = json_decode((string)@file_get_contents("$root/composer.json"), true) ?: [];
        $autoload = [];
        foreach ((array)($composer['autoload']['psr-4'] ?? ['App\\' => 'src/']) as $prefix => $dirs) {
            $autoload[(string)$prefix] = array_values(array_map('strval', (array)$dirs));
        }
        $extra = array_diff(array_keys((array)($composer['require'] ?? [])), ['php', 'jumphp/engine']);
        $extra = array_filter($extra, fn ($p) => !str_starts_with($p, 'ext-'));
        if ($extra) {
            $warnings[] = 'Composer packages other than jumphp/engine are not loaded on the playground: ' . implode(', ', $extra);
        }

        $manifest = ['format' => 1, 'engine' => null] + $info + ['base' => 'files/', 'autoload' => $autoload, 'files' => $loaded];
        return [$errors, $warnings, $manifest];
    }

    /** php -l: parses, never runs. 8 at a time. */
    private function lint(string $root, array $files): array
    {
        $errors = [];
        foreach (array_chunk($files, 8) as $chunk) {
            $processes = [];
            foreach ($chunk as $path) {
                $process = new \Symfony\Component\Process\Process(['php', '-n', '-l', "$root/$path"], null, null, null, 30);
                $process->start();
                $processes[$path] = $process;
            }
            foreach ($processes as $path => $process) {
                $process->wait();
                if (!$process->isSuccessful()) {
                    $message = trim(str_replace("$root/", '', $process->getOutput() . $process->getErrorOutput()));
                    $message = preg_replace('/\s*Errors parsing .*$/s', '', $message);
                    $errors[] = "$path: " . ($message ?: 'syntax error');
                }
            }
        }
        return $errors;
    }

    /** The game's home page (site/index.html): what the portal shows of it (title, description, picture). */
    private function readSite(string $dir, Project $project): ?array
    {
        $file = "$dir/index.html";
        if (!is_file($file)) {
            return null;
        }
        $base = rtrim($this->appUrl, '/') . '/' . $project->getSlug() . '/';
        $html = str_replace('https://{{DOMAIN}}/', $base, (string)file_get_contents($file));
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR);
        $xpath = new \DOMXPath($doc);
        $meta = function (string $query) use ($xpath): string {
            $node = $xpath->query($query)->item(0);
            return $node ? trim((string)($node instanceof \DOMElement ? $node->getAttribute('content') : $node->textContent)) : '';
        };
        $image = $meta('//meta[@property="og:image"]') ?: $meta('//meta[@name="twitter:image"]');
        // only a picture of the site itself (never an outside address)
        $image = str_starts_with($image, $base) ? substr($image, strlen($base)) : (preg_match('#^[a-z0-9_./-]+$#i', $image) ? $image : '');
        $image = $image !== '' && preg_match(GameFiles::IMAGE, $image) && is_file("$dir/" . GameFiles::clean($image)) ? GameFiles::clean($image) : null;
        $titleNode = $xpath->query('//title')->item(0);
        return [
            'title' => mb_substr(trim($titleNode->textContent ?? '') ?: $meta('//meta[@property="og:title"]'), 0, 200),
            'description' => mb_substr($meta('//meta[@name="description"]') ?: $meta('//meta[@property="og:description"]'), 0, 400),
            'image' => $image,
            'lang' => mb_substr((string)$doc->documentElement?->getAttribute('lang'), 0, 10),
        ];
    }

    /** keeps the published version and the one before (a page loaded just before still finds its files) */
    private function prune(Project $project): void
    {
        $dirs = glob("$this->storageDir/published/{$project->getId()}/*", GLOB_ONLYDIR) ?: [];
        usort($dirs, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        foreach (array_slice($dirs, 2) as $dir) {
            if (basename($dir) !== $project->getPublishedSha()) {
                (new Filesystem())->remove($dir);
            }
        }
    }
}
