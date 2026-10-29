<?php

namespace App\Service;

/**
 * Lets the creator's Claude send private files without its MCP token: a signed, short-lived token in the URL
 * given by the MCP tool upload_private_files (/upload/<token>), naming the project and its owner.
 */
final class UploadToken
{
    public const TTL = 2 * 3600;

    public function __construct(#[\SensitiveParameter] private string $kernelSecret)
    {
    }

    public function make(int $projectId, int $userId): string
    {
        $payload = $projectId . '.' . $userId . '.' . (time() + self::TTL);
        return $payload . '.' . $this->sign($payload);
    }

    /** @return array{project: int, user: int}|null */
    public function read(string $token): ?array
    {
        if (!preg_match('/^(\d+)\.(\d+)\.(\d+)\.([A-Za-z0-9_-]{43})$/', $token, $m)) {
            return null;
        }
        $payload = "$m[1].$m[2].$m[3]";
        if (!hash_equals($this->sign($payload), $m[4]) || (int)$m[3] < time()) {
            return null;
        }
        return ['project' => (int)$m[1], 'user' => (int)$m[2]];
    }

    private function sign(string $payload): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', 'upload|' . $payload, $this->kernelSecret, true)), '+/', '-_'), '=');
    }
}
