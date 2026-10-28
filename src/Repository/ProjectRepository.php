<?php

namespace App\Repository;

use App\Entity\Category;
use App\Entity\Project;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Project> */
class ProjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Project::class);
    }

    private function listed(): QueryBuilder
    {
        return $this->createQueryBuilder('p')
            ->addSelect('o', 'c')->join('p.owner', 'o')->join('p.category', 'c')
            ->where('p.listed = true')->andWhere('p.publishedSha IS NOT NULL');
    }

    /** @return Project[] the games of the directory, newest first */
    public function latest(int $limit = 24, ?Category $category = null, int $offset = 0): array
    {
        $qb = $this->listed()->orderBy('p.publishedAt', 'DESC')->setMaxResults($limit)->setFirstResult($offset);
        if ($category) {
            $qb->andWhere('p.category = :c')->setParameter('c', $category);
        }
        return $qb->getQuery()->getResult();
    }

    public function countListed(?Category $category = null): int
    {
        $qb = $this->listed()->select('COUNT(p.id)');
        if ($category) {
            $qb->andWhere('p.category = :c')->setParameter('c', $category);
        }
        return (int)$qb->getQuery()->getSingleScalarResult();
    }

    /** @return Project[] */
    public function search(string $text, int $limit = 48): array
    {
        return $this->listed()
            ->andWhere('p.title LIKE :t OR p.description LIKE :t OR o.login LIKE :t')
            ->setParameter('t', '%' . addcslashes($text, '%_') . '%')
            ->orderBy('p.publishedAt', 'DESC')->setMaxResults($limit)->getQuery()->getResult();
    }

    /** @return Project[] */
    public function ownedBy(User $user): array
    {
        return $this->findBy(['owner' => $user], ['updatedAt' => 'DESC']);
    }

    /** @return Project[] */
    public function publishedBy(User $user): array
    {
        return $this->listed()->andWhere('p.owner = :u')->setParameter('u', $user)
            ->orderBy('p.publishedAt', 'DESC')->getQuery()->getResult();
    }

    public function addPlay(Project $project): void
    {
        $this->getEntityManager()->createQuery('UPDATE App\Entity\Project p SET p.plays = p.plays + 1 WHERE p.id = :id')
            ->setParameter('id', $project->getId())->execute();
    }
}
