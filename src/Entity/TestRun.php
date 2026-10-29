<?php

namespace App\Entity;

use App\Repository\TestRunRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A run of a game's PHPUnit tests (its tests/ folder, as published), in the sandbox of the tester service
 * (App\Service\TestRunner, docker/tester): asked from the studio or by the owner's Claude (MCP run_tests).
 */
#[ORM\Entity(repositoryClass: TestRunRepository::class)]
#[ORM\Index(columns: ['project_id', 'created_at'])]
class TestRun
{
    public const QUEUED = 'queued';
    public const RUNNING = 'running';
    public const PASSED = 'passed';
    public const FAILED = 'failed';     // a test failed
    public const ERROR = 'error';       // the run itself failed (no tests, a timeout, a PHP fatal error)

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 10)]
    private string $status = self::QUEUED;

    /** PHPUnit's output (testdox), truncated */
    #[ORM\Column(type: Types::TEXT)]
    private string $output = '';

    /** tests, assertions, failures, errors, skipped, time, cases: [{class, name, status, message, time}] */
    #[ORM\Column(type: Types::JSON)]
    private array $summary = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Project $project,
        #[ORM\Column(length: 40)]
        private string $sha,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getProject(): Project { return $this->project; }
    public function getSha(): string { return $this->sha; }
    public function getStatus(): string { return $this->status; }
    public function isDone(): bool { return !in_array($this->status, [self::QUEUED, self::RUNNING], true); }
    public function setRunning(): void { $this->status = self::RUNNING; }
    public function getOutput(): string { return $this->output; }
    public function getSummary(): array { return $this->summary; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }

    public function finish(string $status, string $output, array $summary = []): void
    {
        $this->status = $status;
        $this->output = $output;
        $this->summary = $summary;
        $this->finishedAt = new \DateTimeImmutable();
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'commit' => $this->sha,
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'finished_at' => $this->finishedAt?->format(DATE_ATOM),
        ] + $this->summary + ($this->isDone() ? ['output' => $this->output] : []);
    }
}
