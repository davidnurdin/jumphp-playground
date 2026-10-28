<?php

namespace App\Repository;

use App\Entity\Project;
use App\Entity\Rating;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Rating> */
class RatingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Rating::class);
    }

    public function scoreOf(Project $project, ?string $voter): ?int
    {
        return $voter ? $this->findOneBy(['project' => $project, 'voter' => $voter])?->getScore() : null;
    }

    /** A voter's mark (a new one, or a change), then the game's sum and count again. */
    public function rate(Project $project, string $voter, int $score): void
    {
        $em = $this->getEntityManager();
        $rating = $this->findOneBy(['project' => $project, 'voter' => $voter]);
        if ($rating) {
            $rating->setScore($score);
        } else {
            $em->persist(new Rating($project, $voter, $score));
        }
        $em->flush();
        $em->getConnection()->executeStatement(
            'UPDATE project p SET p.rating_sum = (SELECT COALESCE(SUM(r.score), 0) FROM rating r WHERE r.project_id = p.id),
                p.rating_count = (SELECT COUNT(*) FROM rating r WHERE r.project_id = p.id) WHERE p.id = ?',
            [$project->getId()],
        );
        $em->refresh($project);
    }
}
