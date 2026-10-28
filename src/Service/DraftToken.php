<?php

namespace App\Service;

/**
 * Lets the games origin serve a user's drafts without any cookie there: a signed, short-lived token in the
 * URL of the game (/{slug}/play/?draft=...), naming the project and the user.
 */
final class DraftToken
{
    private const TTL = 6 * 3600;

    public function __construct(#[\SensitiveParameter] private string $kernelSecret)
    {
    }

    public function make(int $projectId, int $userId): string
    {
        $payload = $projectId . '.' . $userId . '.' . (time() + self::TTL);
        return $payload . '.' . $this->sign($payload);
    }

    /** @return array{project: int, user: int}|null */
    public function read(string $token, int $projectId): ?array
    {
        if (!preg_match('/^(\d+)\.(\d+)\.(\d+)\.([A-Za-z0-9_-]{43})$/', $token, $m)) {
            return null;
        }
        $payload = "$m[1].$m[2].$m[3]";
        if (!hash_equals($this->sign($payload), $m[4]) || (int)$m[3] < time() || (int)$m[1] !== $projectId) {
            return null;
        }
        return ['project' => (int)$m[1], 'user' => (int)$m[2]];
    }

    private function sign(string $payload): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', 'draft|' . $payload, $this->kernelSecret, true)), '+/', '-_'), '=');
    }
}
