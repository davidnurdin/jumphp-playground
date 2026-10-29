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

    /** The sandbox's visitors forget their drafts after some time without a change (not the shared links': SandboxShare). */
    public function purgeVisitors(\DateTimeImmutable $before): int
    {
        return $this->getEntityManager()->createQuery('DELETE FROM App\Entity\Draft d WHERE d.visitor IS NOT NULL AND d.updatedAt < :b
                AND d.visitor NOT IN (SELECT s.visitor FROM App\Entity\SandboxShare s)')
            ->setParameter('b', $before)->execute();
    }

    /** $to's drafts of $project become a copy of $from's (theirs before are forgotten): how many files */
    public function copy(DraftAuthor $from, DraftAuthor $to, Project $project): int
    {
        $this->clear($to, $project);
        $drafts = $this->of($from, $project);
        foreach ($drafts as $path => $draft) {
            $copy = $to->newDraft($project, $path);
            $draft->isDeleted() ? $copy->markDeleted() : $copy->setContent((string)$draft->getContent());
            $this->getEntityManager()->persist($copy);
        }
        $this->getEntityManager()->flush();
        return count($drafts);
    }

    /** @return array<int, int> project id => how many changed files, the projects a user has drafts of */
    public function countsOf(User $user): array
    {
        $rows = $this->createQueryBuilder('d')->select('IDENTITY(d.project) AS p, COUNT(d.id) AS n')->where('d.user = :u')
            ->setParameter('u', $user)->groupBy('d.project')->getQuery()->getArrayResult();
        return array_column(array_map(fn ($r) => [(int)$r['p'], (int)$r['n']], $rows), 1, 0);
    }

    private static function who(User|DraftAuthor $who): DraftAuthor
    {
        return $who instanceof User ? DraftAuthor::user($who) : $who;
    }
}
