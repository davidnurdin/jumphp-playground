<?php

namespace App\Repository;

use App\Entity\Draft;
use App\Entity\Project;
use App\Entity\User;
use App\Service\DraftAuthor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Draft> */
class DraftRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Draft::class);
    }

    /** @return array<string, Draft> path => draft */
    public function of(User|DraftAuthor $who, Project $project): array
    {
        $drafts = [];
        foreach ($this->findBy(self::who($who)->criteria() + ['project' => $project], ['path' => 'ASC']) as $draft) {
            $drafts[$draft->getPath()] = $draft;
        }
        return $drafts;
    }

    public function forPath(User|DraftAuthor $who, Project $project, string $path): ?Draft
    {
        return $this->findOneBy(self::who($who)->criteria() + ['project' => $project, 'path' => $path]);
    }

    public function clear(User|DraftAuthor $who, Project $project): void
    {
        foreach ($this->findBy(self::who($who)->criteria() + ['project' => $project]) as $draft) {
            $this->getEntityManager()->remove($draft);
        }
        $this->getEntityManager()->flush();
    }

    /** The sandbox's visitors forget their drafts after some time without a change. */
    public function purgeVisitors(\DateTimeImmutable $before): int
    {
        return $this->getEntityManager()->createQuery('DELETE FROM App\Entity\Draft d WHERE d.visitor IS NOT NULL AND d.updatedAt < :b')
            ->setParameter('b', $before)->execute();
    }

    private static function who(User|DraftAuthor $who): DraftAuthor
    {
        return $who instanceof User ? DraftAuthor::user($who) : $who;
    }
}
