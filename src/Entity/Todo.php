<?php

namespace App\Entity;

use App\Repository\TodoRepository;
use Doctrine\ORM\Mapping as ORM;

/** A task of a game's private todo list, kept by its owner's Claude (MCP add_todos / list_todos / update_todos). */
#[ORM\Entity(repositoryClass: TodoRepository::class)]
#[ORM\Index(columns: ['project_id', 'done'])]
class Todo
{
    public const MAX_TEXT = 500;
    public const MAX_COUNT = 500;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $doneAt = null;

    #[ORM\Column]
    private bool $done = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Project $project,
        #[ORM\Column(length: self::MAX_TEXT)]
        private string $text,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getProject(): Project { return $this->project; }
    public function getText(): string { return $this->text; }
    public function setText(string $text): void { $this->text = $text; }
    public function isDone(): bool { return $this->done; }
    public function getDoneAt(): ?\DateTimeImmutable { return $this->doneAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function setDone(bool $done): void
    {
        $this->done = $done;
        $this->doneAt = $done ? new \DateTimeImmutable() : null;
    }
}
