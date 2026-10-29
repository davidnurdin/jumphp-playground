<?php

namespace App\Entity;

use App\Repository\DraftRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A file of a game changed in the online editor, kept for its author (their session of the game: a user, or a
 * visitor of the sandbox) until they push it to GitHub or throw it away. `content` null + `deleted` = the file is removed.
 */
#[ORM\Entity(repositoryClass: DraftRepository::class)]
#[ORM\UniqueConstraint(columns: ['user_id', 'project_id', 'path'])]
#[ORM\UniqueConstraint(columns: ['visitor', 'project_id', 'path'])]
#[ORM\Index(columns: ['visitor', 'updated_at'])]
class Draft
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::BLOB, nullable: true)]
    private mixed $content = null;

    #[ORM\Column]
    private bool $deleted = false;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
        private ?User $user,
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Project $project,
        #[ORM\Column(length: 255)]
        private string $path,
        /** a visitor of the sandbox, without an account (DraftAuthor): the id of their cookie */
        #[ORM\Column(length: 32, nullable: true)]
        private ?string $visitor = null,
    ) {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): ?User { return $this->user; }
    public function getVisitor(): ?string { return $this->visitor; }
    public function getProject(): Project { return $this->project; }
    public function getPath(): string { return $this->path; }
    public function isDeleted(): bool { return $this->deleted; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function getContent(): ?string
    {
        if (is_resource($this->content)) {
            $this->content = stream_get_contents($this->content);
        }
        return $this->content;
    }

    public function setContent(string $content): void
    {
        $this->content = $content;
        $this->deleted = false;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markDeleted(): void
    {
        $this->content = null;
        $this->deleted = true;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getHash(): string
    {
        return substr(hash('sha256', $this->deleted ? '' : (string)$this->getContent()), 0, 16);
    }
}
