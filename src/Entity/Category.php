<?php

namespace App\Entity;

use App\Repository\CategoryRepository;
use Doctrine\ORM\Mapping as ORM;

/** A shelf of the games directory (platform, puzzle...), chosen by the creator. */
#[ORM\Entity(repositoryClass: CategoryRepository::class)]
class Category
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(length: 40, unique: true)]
        private string $slug,
        #[ORM\Column(length: 80)]
        private string $name,
        #[ORM\Column(length: 255)]
        private string $description = '',
        #[ORM\Column]
        private int $position = 0,
    ) {
    }

    public function getId(): ?int { return $this->id; }
    public function getSlug(): string { return $this->slug; }
    public function getName(): string { return $this->name; }
    public function getDescription(): string { return $this->description; }
    public function getPosition(): int { return $this->position; }
}
