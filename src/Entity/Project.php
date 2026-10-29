<?php

namespace App\Entity;

use App\Repository\ProjectRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A game: a JumPHP project in a GitHub repository of its owner (the game in `gameDir`), published on the
 * playground at /{slug} after each push (webhook) or sync.
 */
#[ORM\Entity(repositoryClass: ProjectRepository::class)]
#[ORM\Index(columns: ['listed', 'published_at'])]
class Project
{
    public const STATUS_CREATING = 'creating';   // the repository is being made (InitRepository)
    public const STATUS_EMPTY = 'empty';         // nothing published yet
    public const STATUS_READY = 'ready';         // published
    public const STATUS_ERROR = 'error';         // the last creation / sync failed (statusMessage)

    /** what kind of game: it tells the creator's Claude how to make it (BRIEF.md) */
    public const KINDS = [
        'original' => 'Création originale',
        'cpc' => 'Remake d\'un jeu Amstrad CPC (depuis sa disquette .dsk)',
        'port' => 'Portage d\'un jeu existant (dont on a le code source)',
    ];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: 40, unique: true)]
    private string $slug;

    #[ORM\Column(length: 80)]
    private string $title;

    #[ORM\Column(type: Types::TEXT)]
    private string $description = '';

    /** What the game should be: the creator's words, written to BRIEF.md for their Claude. */
    #[ORM\Column(type: Types::TEXT)]
    private string $brief = '';

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)]
    private Category $category;

    #[ORM\Column(length: 140)]
    private string $repoFullName;

    #[ORM\Column(length: 100)]
    private string $defaultBranch = 'main';

    /** The game's folder in the repository: games/<id>. */
    #[ORM\Column(length: 120)]
    private string $gameDir;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_CREATING;

    #[ORM\Column(type: Types::TEXT)]
    private string $statusMessage = '';

    #[ORM\Column(nullable: true)]
    private ?int $webhookId = null;

    #[ORM\Column(length: 64)]
    private string $webhookSecret;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $publishedSha = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $engineVersion = null;

    /** game.yaml, as published */
    #[ORM\Column(type: Types::JSON)]
    private array $gameInfo = [];

    /** the game's own home page (site/index.html): its title, description, picture */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $site = null;

    #[ORM\Column]
    private bool $hasThumbnail = false;

    /** shown in the directory (an owner can hide a game) */
    #[ORM\Column]
    private bool $listed = true;

    #[ORM\Column(length: 20, options: ['default' => 'original'])]
    private string $kind = 'original';

    /** its picture is a screenshot made by the playground (Screenshots), not one of its own */
    #[ORM\Column]
    private bool $shotThumbnail = false;

    #[ORM\Column]
    private int $plays = 0;

    /** the players' marks (1 to 5): their sum and count, kept up to date by RatingRepository */
    #[ORM\Column]
    private int $ratingSum = 0;

    #[ORM\Column]
    private int $ratingCount = 0;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Project $forkOf = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $owner, string $slug, string $title, Category $category, string $repoFullName, string $gameDir)
    {
        $this->owner = $owner;
        $this->slug = $slug;
        $this->title = $title;
        $this->category = $category;
        $this->repoFullName = $repoFullName;
        $this->gameDir = $gameDir;
        $this->webhookSecret = bin2hex(random_bytes(20));
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getOwner(): User { return $this->owner; }
    public function isOwnedBy(?User $user): bool { return $user !== null && $user->getId() === $this->owner->getId(); }
    public function getSlug(): string { return $this->slug; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): void { $this->title = $title; $this->touch(); }
    public function getDescription(): string { return $this->description; }
    public function setDescription(string $description): void { $this->description = $description; $this->touch(); }
    public function getBrief(): string { return $this->brief; }
    public function setBrief(string $brief): void { $this->brief = $brief; $this->touch(); }
    public function getCategory(): Category { return $this->category; }
    public function setCategory(Category $category): void { $this->category = $category; $this->touch(); }
    /** A path of the game as a path in its repository (the game folder is "." when the repository is the game). */
    public function inRepo(string $path = ''): string
    {
        return ltrim(($this->gameDir === '.' ? '' : $this->gameDir) . ($path !== '' ? "/$path" : ''), '/');
    }
    public function getRepoFullName(): string { return $this->repoFullName; }
    public function setRepoFullName(string $name): void { $this->repoFullName = $name; }
    public function getRepoUrl(): string { return 'https://github.com/' . $this->repoFullName; }
    public function getCloneUrl(): string { return 'https://github.com/' . $this->repoFullName . '.git'; }
    public function getDefaultBranch(): string { return $this->defaultBranch; }
    public function setDefaultBranch(string $branch): void { $this->defaultBranch = $branch; }
    public function getGameDir(): string { return $this->gameDir; }
    public function setGameDir(string $dir): void { $this->gameDir = $dir; }
    public function getGameId(): string { return basename($this->gameDir); }
    public function getStatus(): string { return $this->status; }
    public function getStatusMessage(): string { return $this->statusMessage; }

    public function setStatus(string $status, string $message = ''): void
    {
        $this->status = $status;
        $this->statusMessage = $message;
        $this->touch();
    }

    public function getWebhookId(): ?int { return $this->webhookId; }
    public function setWebhookId(?int $id): void { $this->webhookId = $id; }
    public function getWebhookSecret(): string { return $this->webhookSecret; }
    public function getPublishedSha(): ?string { return $this->publishedSha; }
    public function getPublishedAt(): ?\DateTimeImmutable { return $this->publishedAt; }
    public function isPublished(): bool { return $this->publishedSha !== null; }
    public function getEngineVersion(): ?string { return $this->engineVersion; }
    public function getGameInfo(): array { return $this->gameInfo; }
    public function getSite(): ?array { return $this->site; }
    public function hasSite(): bool { return $this->site !== null; }
    public function hasThumbnail(): bool { return $this->hasThumbnail; }
    public function setHasThumbnail(bool $has): void { $this->hasThumbnail = $has; }
    public function getKind(): string { return $this->kind; }
    public function setKind(string $kind): void { $this->kind = isset(self::KINDS[$kind]) ? $kind : 'original'; }
    public function isShotThumbnail(): bool { return $this->shotThumbnail; }
    public function setShotThumbnail(bool $shot): void { $this->shotThumbnail = $shot; }

    /** After a sync: what is online now. */
    public function publish(string $sha, string $engineVersion, array $gameInfo, ?array $site, bool $hasThumbnail): void
    {
        $this->publishedSha = $sha;
        $this->publishedAt = new \DateTimeImmutable();
        $this->engineVersion = $engineVersion;
        $this->gameInfo = $gameInfo;
        $this->site = $site;
        $this->hasThumbnail = $hasThumbnail;
        $this->shotThumbnail = false;
        $this->setStatus(self::STATUS_READY);
    }

    public function isListed(): bool { return $this->listed; }
    public function setListed(bool $listed): void { $this->listed = $listed; $this->touch(); }
    public function getPlays(): int { return $this->plays; }
    public function getRatingCount(): int { return $this->ratingCount; }
    /** the average mark, 1 to 5, or null without any */
    public function getRating(): ?float { return $this->ratingCount ? round($this->ratingSum / $this->ratingCount, 1) : null; }
    public function getForkOf(): ?Project { return $this->forkOf; }
    public function setForkOf(?Project $project): void { $this->forkOf = $project; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    private function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
}
