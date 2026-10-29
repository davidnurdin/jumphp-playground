<?php

namespace App\Service;

use App\Entity\Draft;
use App\Entity\Project;

/**
 * The published files of a game: var/storage/published/<project id>/<sha>/
 *   game/          the game's folder at that commit (games/<id>/ of the repository, without vendor/, var/...)
 *   manifest.json  what the browser loads (JumPHP docs/remote.md), relative to /<slug>/play/
 *   thumb.png      the picture of the directory
 */
final class GameFiles
{
    /** files the browser does not load (the same as JumPHP tools/remote-manifest.php) */
    public const NOT_LOADED = '#^(vendor|dist|\.build|var|cache|tests|site|\.git[^/]*)(/|$)|^phpstan\.neon$|(^|/)core(\.[0-9]+)?$|(^|/)\.DS_Store$#';

    public const TEXT = '/\.(php|ya?ml|json|md|txt|html?|css|js|mjs|xml|tmj|tsj|tmx|tsx|csv|ini|neon|sh|py|svg|glsl|vert|frag|lua|gitignore|gitkeep|dist)$/i';
    public const IMAGE = '/\.(png|jpe?g|gif|webp|ico)$/i';
    public const MAX_EDIT = 512 * 1024;

    public function __construct(private string $storageDir, private PrivateFiles $private)
    {
    }

    public function dir(Project $project, ?string $sha = null): ?string
    {
        $sha ??= $project->getPublishedSha();
        $dir = $sha ? "$this->storageDir/published/{$project->getId()}/$sha" : null;
        return $dir && is_dir($dir) ? $dir : null;
    }

    /** An existing file of the published game, never outside it; $path relative to the game folder. */
    public function path(Project $project, string $path): ?string
    {
        $dir = $this->dir($project);
        $path = self::clean($path);
        if (!$dir || $path === null) {
            return null;
        }
        $file = "$dir/game/$path";
        return is_file($file) && !is_link($file) ? $file : null;
    }

    public static function clean(string $path): ?string
    {
        $parts = array_filter(explode('/', str_replace('\\', '/', $path)), fn ($p) => $p !== '' && $p !== '.');
        foreach ($parts as $part) {
            if ($part === '..' || str_contains($part, "\0")) {
                return null;
            }
        }
        return $parts ? implode('/', $parts) : null;
    }

    /** What the browser loads: the published files, and the owner's private files on top (PrivateFiles). */
    /** the manifest of the published version, or of the version $sha (Publisher::version()) */
    public function manifest(Project $project, ?string $sha = null): ?array
    {
        $dir = $this->dir($project, $sha);
        $manifest = $dir ? json_decode((string)file_get_contents("$dir/manifest.json"), true) : null;
        if (!$manifest || !($private = $this->private->index($project))) {
            return $manifest;
        }
        $files = array_column($manifest['files'], null, 'path');
        foreach ($private as $path => $file) {
            $files[$path] = ['path' => $path, 'hash' => $file['hash'], 'size' => $file['size'],
                'url' => 'private/' . implode('/', array_map('rawurlencode', explode('/', $path))) . '?v=' . $file['hash']];
        }
        ksort($files, SORT_STRING);
        $manifest['files'] = array_values($files);
        return $manifest;
    }

    /**
     * The manifest with a user's drafts on top: changed files come from /<slug>/play/draft/<token>/<path>,
     * removed ones leave.
     *
     * @param Draft[] $drafts
     */
    public function manifestWithDrafts(Project $project, array $drafts, string $token): ?array
    {
        $manifest = $this->manifest($project);
        if (!$manifest) {
            return null;
        }
        $files = [];
        foreach ($manifest['files'] as $file) {
            $files[$file['path']] = $file;
        }
        foreach ($drafts as $draft) {
            $path = $draft->getPath();
            if (preg_match(self::NOT_LOADED, $path)) {
                continue;
            }
            if ($draft->isDeleted()) {
                unset($files[$path]);
                continue;
            }
            $files[$path] = [
                'path' => $path,
                'hash' => $draft->getHash(),
                'size' => strlen((string)$draft->getContent()),
                'url' => 'draft/' . $token . '/' . implode('/', array_map('rawurlencode', explode('/', $path))) . '?v=' . $draft->getHash(),
            ];
        }
        ksort($files);
        $manifest['files'] = array_values($files);
        return $manifest;
    }

    /**
     * Every file of the published game (for the source browser and the editor).
     *
     * @return array<string, int> path => size
     */
    public function tree(Project $project, ?string $sha = null): array
    {
        $dir = $this->dir($project, $sha);
        if (!$dir) {
            return [];
        }
        $files = [];
        $root = "$dir/game";
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && !$file->isLink()) {
                $files[substr($file->getPathname(), strlen($root) + 1)] = $file->getSize();
            }
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    public function thumbnail(Project $project): ?string
    {
        $dir = $this->dir($project);
        return $dir && is_file("$dir/thumb.png") ? "$dir/thumb.png" : null;
    }

    public static function isText(string $path): bool
    {
        return (bool)preg_match(self::TEXT, $path) || !str_contains(basename($path), '.');
    }

    public static function mime(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'html', 'htm' => 'text/html; charset=utf-8',
            'css' => 'text/css; charset=utf-8',
            'js', 'mjs' => 'text/javascript; charset=utf-8',
            'json', 'tmj', 'tsj', 'webmanifest' => 'application/json',
            'xml' => 'application/xml',
            'txt', 'md', 'php', 'yaml', 'yml', 'ini', 'neon', 'csv' => 'text/plain; charset=utf-8',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            'svg' => 'image/svg+xml',
            'ogg' => 'audio/ogg',
            'wav' => 'audio/wav',
            'mp3' => 'audio/mpeg',
            'ttf' => 'font/ttf',
            'woff2' => 'font/woff2',
            'wasm' => 'application/wasm',
            default => 'application/octet-stream',
        };
    }
}
