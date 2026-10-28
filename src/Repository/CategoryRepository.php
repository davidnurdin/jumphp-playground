<?php

namespace App\Repository;

use App\Entity\Category;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Category> */
class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    /** @return Category[] */
    public function ordered(): array
    {
        return $this->findBy([], ['position' => 'ASC', 'name' => 'ASC']);
    }

    /** @return array<int, int> category id => number of listed games */
    public function counts(): array
    {
        $rows = $this->getEntityManager()->createQuery(
            'SELECT IDENTITY(p.category) AS id, COUNT(p.id) AS n FROM App\Entity\Project p
             WHERE p.listed = true AND p.publishedSha IS NOT NULL GROUP BY p.category'
        )->getArrayResult();
        return array_column($rows, 'n', 'id');
    }
}
