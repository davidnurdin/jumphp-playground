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

    public const SORTS = ['note' => 'les mieux notés', 'parties' => 'les plus joués', 'date' => 'les plus récents', 'nom' => 'par nom'];

    /**
     * All the games of the directory, in an order of SORTS. By mark: the average pulled towards 3 by three
     * phantom votes, so that one 5-star mark does not beat many 4.5 ones.
     *
     * @return Project[]
     */
    public function sorted(string $sort): array
    {
        $qb = $this->listed();
        match ($sort) {
            'note' => $qb->addSelect('(p.ratingSum + 9) / (p.ratingCount + 3) AS HIDDEN score')->orderBy('score', 'DESC')->addOrderBy('p.plays', 'DESC'),
            'parties' => $qb->orderBy('p.plays', 'DESC'),
            'nom' => $qb->orderBy('p.title', 'ASC'),
            default => $qb->orderBy('p.publishedAt', 'DESC'),
        };
        return $qb->addOrderBy('p.publishedAt', 'DESC')->getQuery()->getResult();
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

    /** The fields the public API sorts the games by (GameProvider). */
    public const API_ORDERS = ['rating', 'plays', 'publishedAt', 'title'];

    /**
     * One page of the games of the public API, filtered, sorted (fields of API_ORDERS => ASC|DESC, in the order
     * given; by mark as in sorted()).
     *
     * @param array<string, string> $order
     * @return array{Project[], int} the page, and how many games match in all
     */
    public function forApi(?string $category, ?string $kind, array $order, int $offset, int $limit): array
    {
        $qb = $this->listed();
        if ($category !== null) {
            $qb->andWhere('c.slug = :category')->setParameter('category', $category);
        }
        if ($kind !== null) {
            $qb->andWhere('p.kind = :kind')->setParameter('kind', $kind);
        }
        $total = (int)(clone $qb)->select('COUNT(p.id)')->getQuery()->getSingleScalarResult();
        foreach ($order as $field => $direction) {
            match ($field) {
                'rating' => $qb->addSelect('(p.ratingSum + 9) / (p.ratingCount + 3) AS HIDDEN score')->addOrderBy('score', $direction),
                default => $qb->addOrderBy('p.' . $field, $direction),
            };
        }
        $qb->addOrderBy('p.publishedAt', 'DESC')->addOrderBy('p.id', 'DESC');
        return [$qb->setFirstResult($offset)->setMaxResults($limit)->getQuery()->getResult(), $total];
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
