<?php

namespace App\Entity;

use App\Repository\CommentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * What a player says about a game: a comment, an idea (the idea box) or an issue (a problem to fix). A signed-in
 * user's is shown at once, with their picture and a link to their profile; an anonymous one (a name of their
 * choice, the browser's id from the jumphp_voter cookie) waits until the game's owner approves it: until then
 * only the owner and its writer see it.
 */
#[ORM\Entity(repositoryClass: CommentRepository::class)]
#[ORM\Index(columns: ['project_id', 'kind', 'approved', 'created_at'])]
#[ORM\Index(columns: ['ip_hash', 'created_at'])]
class Comment
{
    /** the kinds, by their name in the URLs (/<slug>/commentaires, /<slug>/idees, /<slug>/problemes) */
    public const SECTIONS = ['commentaires' => 'comment', 'idees' => 'idea', 'problemes' => 'issue'];
    public const MAX_LENGTH = 2000;
    public const MAX_NAME = 40;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private bool $approved;

    /** an issue the owner fixed */
    #[ORM\Column(options: ['default' => false])]
    private bool $resolved = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Project $project,
        #[ORM\Column(type: Types::TEXT)]
        private string $body,
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
        private ?User $author = null,
        /** an anonymous writer's name */
        #[ORM\Column(length: self::MAX_NAME, nullable: true)]
        private ?string $name = null,
        /** an anonymous writer's browser (Voters: "b<id>"), so that they see their comment waiting */
        #[ORM\Column(length: 40, nullable: true)]
        private ?string $visitor = null,
        /** sha256 of the writer's address and the app secret: how many comments a moment from it */
        #[ORM\Column(length: 64)]
        private string $ipHash = '',
        #[ORM\Column(length: 10, options: ['default' => 'comment'])]
        private string $kind = 'comment',
    ) {
        $this->approved = $author !== null;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getProject(): Project { return $this->project; }
    public function getBody(): string { return $this->body; }
    public function getAuthor(): ?User { return $this->author; }
    public function isAnonymous(): bool { return $this->author === null; }
    public function getName(): ?string { return $this->name; }
    public function getVisitor(): ?string { return $this->visitor; }
    public function isApproved(): bool { return $this->approved; }
    public function approve(): void { $this->approved = true; }
    public function getKind(): string { return $this->kind; }
    public function getSection(): string { return (string)array_search($this->kind, self::SECTIONS, true); }
    public function isIssue(): bool { return $this->kind === 'issue'; }
    public function isResolved(): bool { return $this->resolved; }
    public function setResolved(bool $resolved): void { $this->resolved = $resolved; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** who may remove it: the game's owner, or its signed-in writer */
    public function canBeRemovedBy(?User $user): bool
    {
        return $user !== null && ($this->project->isOwnedBy($user) || $this->author?->getId() === $user->getId());
    }
}
