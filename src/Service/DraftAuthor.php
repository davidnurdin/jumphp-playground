<?php

namespace App\Service;

use App\Entity\Draft;
use App\Entity\Project;
use App\Entity\User;

/**
 * Who owns drafts: a signed-in user (the online editor), or a visitor of the sandbox without an account, known
 * by the random id of their jumphp_sandbox cookie. As text (the preview's token): "u<id>" or "v<32 hex>".
 */
final class DraftAuthor
{
    private function __construct(public readonly ?User $user, public readonly ?string $visitor)
    {
    }

    public static function user(User $user): self
    {
        return new self($user, null);
    }

    public static function visitor(string $id): self
    {
        if (!preg_match('/^[0-9a-f]{32}$/', $id)) {
            throw new \InvalidArgumentException('visitor id');
        }
        return new self(null, $id);
    }

    public function isVisitor(): bool
    {
        return $this->visitor !== null;
    }

    public function key(): string
    {
        return $this->user ? 'u' . $this->user->getId() : 'v' . $this->visitor;
    }

    /** @return array<string, mixed> the Doctrine criteria of their drafts */
    public function criteria(): array
    {
        return $this->user ? ['user' => $this->user] : ['visitor' => $this->visitor];
    }

    public function newDraft(Project $project, string $path): Draft
    {
        return new Draft($this->user, $project, $path, $this->visitor);
    }
}
