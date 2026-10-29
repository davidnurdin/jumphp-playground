<?php

namespace App\Repository;

use App\Entity\SandboxShare;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SandboxShare> */
class SandboxShareRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SandboxShare::class);
    }

    /** a link that still works */
    public function live(string $code): ?SandboxShare
    {
        $share = $this->findOneBy(['code' => $code]);
        return $share && !$share->isExpired() ? $share : null;
    }

    /** @return SandboxShare[] the links a user made, newest first */
    public function of(User $user): array
    {
        return $this->createQueryBuilder('s')->where('s.user = :u')->andWhere('s.expiresAt IS NULL OR s.expiresAt > :now')
            ->setParameter('u', $user)->setParameter('now', new \DateTimeImmutable())
            ->orderBy('s.createdAt', 'DESC')->getQuery()->getResult();
    }

    /** how many links an author made since $since (a limit against floods) */
    public function countFrom(string $author, \DateTimeImmutable $since): int
    {
        return (int)$this->createQueryBuilder('s')->select('COUNT(s.id)')->where('s.author = :a')->andWhere('s.createdAt > :since')
            ->setParameter('a', $author)->setParameter('since', $since)->getQuery()->getSingleScalarResult();
    }

    /** The expired links are forgotten, with their files. */
    public function purge(): int
    {
        $expired = $this->createQueryBuilder('s')->where('s.expiresAt < :now')->setParameter('now', new \DateTimeImmutable())
            ->setMaxResults(200)->getQuery()->getResult();
        foreach ($expired as $share) {
            $this->remove($share);
        }
        $this->getEntityManager()->flush();
        return count($expired);
    }

    public function remove(SandboxShare $share): void
    {
        $this->getEntityManager()->createQuery('DELETE FROM App\Entity\Draft d WHERE d.visitor = :v')
            ->setParameter('v', $share->getVisitor())->execute();
        $this->getEntityManager()->remove($share);
    }
}
