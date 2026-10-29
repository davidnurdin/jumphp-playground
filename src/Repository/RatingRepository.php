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

    /** @return array<int, int> how many players gave 1 to 5 stars */
    public function spread(Project $project): array
    {
        $rows = $this->createQueryBuilder('r')->select('r.score AS score, COUNT(r.id) AS n')->where('r.project = :p')
            ->setParameter('p', $project)->groupBy('r.score')->getQuery()->getArrayResult();
        return array_replace(array_fill(1, 5, 0), array_column(array_map(fn ($r) => [(int)$r['score'], (int)$r['n']], $rows), 1, 0));
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
