<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;

/** A creator or a player, always signed in with GitHub. */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
class User implements UserInterface
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(unique: true)]
    private int $githubId;

    #[ORM\Column(length: 39)]
    private string $login;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $avatarUrl = null;

    /** The GitHub OAuth token, encrypted (App\Security\TokenCipher). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $githubToken = null;

    #[ORM\Column(length: 255)]
    private string $githubScopes = '';

    /** sha256 of the MCP token: the token itself is shown once. */
    #[ORM\Column(length: 64, unique: true, nullable: true)]
    private ?string $mcpTokenHash = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $mcpTokenHint = null;

    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    /** the notifications (messages on their games) seen until then: the newer ones are new */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $notificationsSeenAt = null;

    public function __construct(int $githubId, string $login)
    {
        $this->githubId = $githubId;
        $this->login = $login;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getNotificationsSeenAt(): ?\DateTimeImmutable { return $this->notificationsSeenAt; }
    public function setNotificationsSeenAt(\DateTimeImmutable $at): void { $this->notificationsSeenAt = $at; }
    public function getGithubId(): int { return $this->githubId; }
    public function getLogin(): string { return $this->login; }
    public function setLogin(string $login): void { $this->login = $login; }
    public function getName(): ?string { return $this->name; }
    public function setName(?string $name): void { $this->name = $name; }
    public function getDisplayName(): string { return $this->name ?: $this->login; }
    public function getAvatarUrl(): ?string { return $this->avatarUrl; }
    public function setAvatarUrl(?string $url): void { $this->avatarUrl = $url; }
    public function getGithubToken(): ?string { return $this->githubToken; }
    public function setGithubToken(?string $encrypted, string $scopes = ''): void { $this->githubToken = $encrypted; $this->githubScopes = $scopes; }
    public function getGithubScopes(): string { return $this->githubScopes; }
    public function getMcpTokenHint(): ?string { return $this->mcpTokenHint; }
    public function hasMcpToken(): bool { return $this->mcpTokenHash !== null; }

    public function setMcpToken(?string $hash, ?string $hint): void
    {
        $this->mcpTokenHash = $hash;
        $this->mcpTokenHint = $hint;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function touchLogin(): void { $this->lastLoginAt = new \DateTimeImmutable(); }

    public function getRoles(): array { return array_values(array_unique([...$this->roles, 'ROLE_USER'])); }
    public function setRoles(array $roles): void { $this->roles = $roles; }
    public function getUserIdentifier(): string { return (string)$this->githubId; }
    public function eraseCredentials(): void { }
}
