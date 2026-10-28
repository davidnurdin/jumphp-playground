<?php

namespace App\Repository;

use App\Entity\Draft;
use App\Entity\Project;
use App\Entity\User;
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
    public function of(User $user, Project $project): array
    {
        $drafts = [];
        foreach ($this->findBy(['user' => $user, 'project' => $project], ['path' => 'ASC']) as $draft) {
            $drafts[$draft->getPath()] = $draft;
        }
        return $drafts;
    }

    public function forPath(User $user, Project $project, string $path): ?Draft
    {
        return $this->findOneBy(['user' => $user, 'project' => $project, 'path' => $path]);
    }

    public function clear(User $user, Project $project): void
    {
        $this->getEntityManager()->createQuery('DELETE FROM App\Entity\Draft d WHERE d.user = :u AND d.project = :p')
            ->setParameter('u', $user)->setParameter('p', $project)->execute();
    }
}
