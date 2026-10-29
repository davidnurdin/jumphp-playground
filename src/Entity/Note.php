<?php

namespace App\Entity;

use App\Repository\NoteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A private note of a game's owner, kept by their Claude (MCP save_note / get_notes): where the game stands, what
 * was decided, what is next. Only its owner reads it (their Claude, their studio).
 */
#[ORM\Entity(repositoryClass: NoteRepository::class)]
#[ORM\UniqueConstraint(columns: ['project_id', 'name'])]
class Note
{
    public const DEFAULT = 'progress';
    public const MAX_SIZE = 200_000;
    public const MAX_COUNT = 50;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $content = '';

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Project $project,
        #[ORM\Column(length: 60)]
        private string $name,
    ) {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getProject(): Project { return $this->project; }
    public function getName(): string { return $this->name; }
    public function getContent(): string { return $this->content; }
    public function setContent(string $content): void { $this->content = $content; $this->updatedAt = new \DateTimeImmutable(); }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
