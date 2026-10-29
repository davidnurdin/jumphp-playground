<?php

namespace App\Service;

use App\Entity\Project;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Files a game needs but that may not be in its public repository (the data of an original game, sounds ripped
 * from it): its owner gives them on the playground (Studio), the game loads them like its own files. They are in
 * no repository, no source page, no fork; only the game's player downloads them (as it would on any web site).
 * storage/private/<project id>/files/<path> and index.json {path: {hash, size}}.
 */
final class PrivateFiles
{
    public const MAX_FILE = 20 * 1024 * 1024;
    public const MAX_TOTAL = 64 * 1024 * 1024;
    public const MAX_COUNT = 2000;

    public function __construct(private string $storageDir)
    {
    }

    /** @return array<string, array{hash: string, size: int}> */
    public function index(Project $project): array
    {
        $file = $this->dir($project) . '/index.json';
        return is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
    }

    public function path(Project $project, string $path): ?string
    {
        $path = GameFiles::clean($path);
        $file = $path !== null && isset($this->index($project)[$path]) ? $this->dir($project) . "/files/$path" : null;
        return $file && is_file($file) ? $file : null;
    }

    /** Adds or replaces a file (from a temporary file); returns its clean path. */
    public function add(Project $project, string $path, string $source): string
    {
        $clean = GameFiles::clean($path);
        if ($clean === null || preg_match(GameFiles::NOT_LOADED, $clean)) {
            throw new ProjectException("Chemin refusé : $path");
        }
        $size = (int)filesize($source);
        if ($size > self::MAX_FILE) {
            throw new ProjectException(sprintf('%s : %d Mo au plus par fichier.', $clean, self::MAX_FILE / 1048576));
        }
        $index = $this->index($project);
        $total = array_sum(array_column($index, 'size')) - ($index[$clean]['size'] ?? 0) + $size;
        if ($total > self::MAX_TOTAL || (!isset($index[$clean]) && count($index) >= self::MAX_COUNT)) {
            throw new ProjectException(sprintf('Fichiers privés : %d Mo et %d fichiers au plus par jeu.', self::MAX_TOTAL / 1048576, self::MAX_COUNT));
        }
        (new Filesystem())->copy($source, $this->dir($project) . "/files/$clean", true);
        $index[$clean] = ['hash' => substr(hash_file('sha256', $source), 0, 16), 'size' => $size];
        $this->save($project, $index);
        return $clean;
    }

    public function remove(Project $project, string $path): void
    {
        $index = $this->index($project);
        $clean = (string)GameFiles::clean($path);
        unset($index[$clean]);
        (new Filesystem())->remove($this->dir($project) . "/files/$clean");
        $this->save($project, $index);
    }

    public function removeAll(Project $project): void
    {
        (new Filesystem())->remove($this->dir($project));
    }

    /** @param array<string, array{hash: string, size: int}> $index */
    private function save(Project $project, array $index): void
    {
        ksort($index, SORT_STRING);
        (new Filesystem())->dumpFile($this->dir($project) . '/index.json', json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function dir(Project $project): string
    {
        return "$this->storageDir/private/{$project->getId()}";
    }
}
