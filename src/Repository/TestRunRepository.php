<?php

namespace App\Repository;

use App\Entity\Project;
use App\Entity\TestRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<TestRun> */
class TestRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TestRun::class);
    }

    /** @return TestRun[] a game's last runs, newest first */
    public function latest(Project $project, int $limit = 10): array
    {
        return $this->findBy(['project' => $project], ['createdAt' => 'DESC', 'id' => 'DESC'], $limit);
    }

    /** a run not finished yet of the game */
    public function pending(Project $project): ?TestRun
    {
        return $this->createQueryBuilder('r')->where('r.project = :p')->andWhere('r.status IN (:s)')
            ->setParameter('p', $project)->setParameter('s', [TestRun::QUEUED, TestRun::RUNNING])
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    /** the old runs of a game go, the last ones stay */
    public function prune(Project $project, int $keep = 20): void
    {
        $ids = array_map(fn (TestRun $r) => $r->getId(), $this->latest($project, $keep));
        $this->createQueryBuilder('r')->delete()->where('r.project = :p')->andWhere('r.id NOT IN (:ids)')
            ->setParameter('p', $project)->setParameter('ids', $ids ?: [0])->getQuery()->execute();
    }
}
