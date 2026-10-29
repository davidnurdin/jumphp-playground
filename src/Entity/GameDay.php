<?php

namespace App\Entity;

use App\Repository\GameDayRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A game's day, for its creator's statistics (studio): the visits of its page, the games played, the launches of
 * the installed game (the PWA: /jouer?app=1). Robots are not counted. One row per game and day (GameDayRepository::tally).
 */
#[ORM\Entity(repositoryClass: GameDayRepository::class)]
#[ORM\UniqueConstraint(columns: ['project_id', 'day'])]
class GameDay
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $visits = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $plays = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $launches = 0;

    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Project $project,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $day,
    ) {
    }

    public function getProject(): Project { return $this->project; }
    public function getDay(): \DateTimeImmutable { return $this->day; }
    public function getVisits(): int { return $this->visits; }
    public function getPlays(): int { return $this->plays; }
    public function getLaunches(): int { return $this->launches; }
}
