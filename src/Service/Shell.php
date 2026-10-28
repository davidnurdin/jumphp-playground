<?php

namespace App\Service;

use Symfony\Component\Process\Process;

/** Runs git / tar / php -l: arguments never through a shell, secrets only in the environment. */
final class Shell
{
    /** @param string[] $command */
    public function run(array $command, ?string $cwd = null, array $env = [], int $timeout = 300): string
    {
        $process = new Process($command, $cwd, $env + ['GIT_TERMINAL_PROMPT' => '0', 'LC_ALL' => 'C'], null, $timeout);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException(sprintf('%s failed: %s', $command[0] . ' ' . ($command[1] ?? ''),
                trim($process->getErrorOutput() ?: $process->getOutput())));
        }
        return $process->getOutput();
    }

    /** @param string[] $command */
    public function tryRun(array $command, ?string $cwd = null): ?string
    {
        try {
            return $this->run($command, $cwd);
        } catch (\RuntimeException) {
            return null;
        }
    }

    /** git with a GitHub token for https (http.extraHeader through GIT_CONFIG_*: not in argv, not in a file) */
    public static function gitAuth(string $login, string $token): array
    {
        return [
            'GIT_CONFIG_COUNT' => '1',
            'GIT_CONFIG_KEY_0' => 'http.https://github.com/.extraheader',
            'GIT_CONFIG_VALUE_0' => 'Authorization: Basic ' . base64_encode($login . ':' . $token),
        ];
    }
}
