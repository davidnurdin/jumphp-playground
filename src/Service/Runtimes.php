<?php

namespace App\Service;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The shared JumPHP engines (JumPHP `bin/jumphp runtime:build`, docs/remote.md), one folder per version in
 * public/engine/<version>/: served once to the browser and cached for every game. A game gets the version of
 * its repository (VERSION at its root) when it is installed here, else the newest one.
 */
final class Runtimes
{
    private const RELEASES = 'https://github.com/davidnurdin/jumPhp/releases/download';

    public function __construct(
        private string $engineDir,
        private string $runtimes,
        private HttpClientInterface $httpClient,
        private Shell $shell,
    ) {
    }

    /** @return string[] installed versions, oldest first */
    public function installed(): array
    {
        $versions = [];
        foreach (glob($this->engineDir . '/*/runtime.json') ?: [] as $file) {
            $versions[] = basename(dirname($file));
        }
        usort($versions, 'version_compare');
        return $versions;
    }

    /** @return string[] the versions JUMPHP_RUNTIMES asks for */
    public function wanted(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', str_replace(' ', ',', $this->runtimes)))));
    }

    public function pick(?string $version): ?string
    {
        $installed = $this->installed();
        $version = ltrim(trim((string)$version), 'v');
        if ($version !== '' && in_array($version, $installed, true)) {
            return $version;
        }
        return $installed ? end($installed) : null;
    }

    public function dir(string $version): string
    {
        return $this->engineDir . '/' . $version;
    }

    /** runtime.json + version.json of an installed engine */
    public function info(string $version): array
    {
        $dir = $this->dir($version);
        return [
            'runtime' => json_decode((string)@file_get_contents("$dir/runtime.json"), true) ?: [],
            'hashes' => json_decode((string)@file_get_contents("$dir/version.json"), true) ?: [],
            'page' => (string)@file_get_contents("$dir/page.html"),
        ];
    }

    /** Downloads the release asset of $version (or copies a local build/runtime folder). */
    public function install(string $version, ?string $from = null): string
    {
        $version = ltrim($version, 'v');
        if (!preg_match('/^\d+\.\d+\.\d+([-.][a-z0-9.]+)?$/i', $version)) {
            throw new \InvalidArgumentException("not a version: $version");
        }
        $fs = new Filesystem();
        $target = $this->dir($version);
        $tmp = $target . '.tmp-' . bin2hex(random_bytes(4));
        $fs->mkdir($tmp);
        try {
            if ($from !== null) {
                if (!is_file("$from/runtime.json")) {
                    throw new \RuntimeException("$from is not a JumPHP runtime (bin/jumphp runtime:build)");
                }
                $fs->mirror($from, $tmp);
            } else {
                $url = self::RELEASES . "/v$version/jumphp-runtime.tar.gz";
                $archive = $this->httpClient->request('GET', $url, ['headers' => ['User-Agent' => 'jumphp-playground']])->getContent();
                $sum = trim(explode(' ', $this->httpClient->request('GET', "$url.sha256", ['headers' => ['User-Agent' => 'jumphp-playground']])->getContent())[0]);
                if (!hash_equals($sum, hash('sha256', $archive))) {
                    throw new \RuntimeException("checksum mismatch for $url");
                }
                file_put_contents("$tmp/runtime.tar.gz", $archive);
                $this->shell->run(['tar', '-xzf', 'runtime.tar.gz', '--no-same-owner'], $tmp);
                unlink("$tmp/runtime.tar.gz");
            }
            if (!is_file("$tmp/runtime.json") || !is_file("$tmp/jumphp-vendor.pack")) {
                throw new \RuntimeException("the runtime $version is incomplete");
            }
            $fs->remove($target);
            $fs->rename($tmp, $target);
        } finally {
            $fs->remove($tmp);
        }
        return $target;
    }
}
