<?php

namespace App\Service;

/** What a sync did: shown in the studio and returned to the creator's Claude (MCP sync_project). */
final class SyncResult
{
    public const PUBLISHED = 'published';
    public const UNCHANGED = 'unchanged';
    public const FAILED = 'failed';

    /**
     * @param string[] $errors   why it was not published (the previous version stays online)
     * @param string[] $warnings published, but worth fixing
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $sha = null,
        public readonly array $errors = [],
        public readonly array $warnings = [],
        public readonly int $files = 0,
    ) {
    }

    public function toArray(): array
    {
        return ['status' => $this->status, 'commit' => $this->sha, 'files' => $this->files,
            'errors' => $this->errors, 'warnings' => $this->warnings];
    }
}
