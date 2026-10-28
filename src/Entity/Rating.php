<?php

namespace App\Entity;

use App\Repository\RatingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A player's mark for a game, 1 to 5 stars. The voter is "u<id>" for a signed-in creator, else the random id of
 * the browser's jumphp_voter cookie: one mark per voter and game, changed by voting again.
 */
#[ORM\Entity(repositoryClass: RatingRepository::class)]
#[ORM\UniqueConstraint(columns: ['project_id', 'voter'])]
class Rating
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Project $project,
        #[ORM\Column(length: 40)]
        private string $voter,
        #[ORM\Column(type: Types::SMALLINT)]
        private int $score,
    ) {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getScore(): int { return $this->score; }
    public function setScore(int $score): void { $this->score = $score; $this->updatedAt = new \DateTimeImmutable(); }
}
