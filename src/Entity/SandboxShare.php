<?php

namespace App\Entity;

use App\Repository\SandboxShareRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A sandbox (or editor) session shared by a link (/bac-a-sable/partage/<code>): a frozen copy of its changed files,
 * kept as the drafts of a visitor of its own (`visitor`), so that it plays like any sandbox. Whoever opens the
 * link plays it, continues it in their own sandbox, or (signed in) imports it into their space. Forgotten at
 * `expiresAt` (null: kept until its author removes it).
 */
#[ORM\Entity(repositoryClass: SandboxShareRepository::class)]
#[ORM\Index(columns: ['expires_at'])]
class SandboxShare
{
    public const DURATIONS = ['day' => '+1 day', 'week' => '+1 week', 'month' => '+1 month', 'forever' => null];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16, unique: true)]
    private string $code;

    /** the visitor that holds the frozen drafts (DraftAuthor) */
    #[ORM\Column(length: 32, unique: true)]
    private string $visitor;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt;

    #[ORM\Column]
    private int $views = 0;

    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Project $project,
        /** who shared it: the DraftAuthor key ("u<id>" or "v<visitor>") */
        #[ORM\Column(length: 40)]
        private string $author,
        string $duration,
        #[ORM\Column(length: 80)]
        private string $title = '',
        /** the signed-in user who shared it (they see and remove it in their studio) */
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?User $user = null,
    ) {
        if (!array_key_exists($duration, self::DURATIONS)) {
            throw new \InvalidArgumentException('duration');
        }
        $this->code = self::randomCode();
        $this->visitor = bin2hex(random_bytes(16));
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = self::DURATIONS[$duration] !== null ? $this->createdAt->modify(self::DURATIONS[$duration]) : null;
    }

    private static function randomCode(): string
    {
        $chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 10; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $code;
    }

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function getVisitor(): string { return $this->visitor; }
    public function getProject(): Project { return $this->project; }
    public function getAuthor(): string { return $this->author; }
    public function getUser(): ?User { return $this->user; }
    public function getTitle(): string { return $this->title; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getExpiresAt(): ?\DateTimeImmutable { return $this->expiresAt; }
    public function getViews(): int { return $this->views; }

    public function isExpired(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt < new \DateTimeImmutable();
    }

    public function viewed(): void
    {
        $this->views++;
    }
}
