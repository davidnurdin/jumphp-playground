<?php

namespace App\Repository;

use App\Entity\Comment;
use App\Entity\Project;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Comment> */
class CommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Comment::class);
    }

    private function withAuthors(): QueryBuilder
    {
        return $this->createQueryBuilder('m')->addSelect('a')->leftJoin('m.author', 'a');
    }

    /**
     * A game's comments (or ideas, issues) as a visitor sees them, newest first: the approved ones, and those waiting for the owner
     * when the visitor is the owner (all) or their anonymous writer (their own). One page of them.
     */
    public function shownTo(Project $project, string $kind, ?User $user, ?string $visitor, int $page = 1, int $perPage = 20): Paged
    {
        $qb = $this->withAuthors()->where('m.project = :p')->andWhere('m.kind = :k')->setParameter('p', $project)->setParameter('k', $kind)->orderBy('m.createdAt', 'DESC')->addOrderBy('m.id', 'DESC');
        if (!$project->isOwnedBy($user)) {
            $qb->andWhere($visitor ? '(m.approved = true OR m.visitor = :v)' : 'm.approved = true');
            if ($visitor) {
                $qb->setParameter('v', $visitor);
            }
        }
        return Paged::of($qb, $page, $perPage);
    }

    /** @return array<int, int> project id => anonymous comments waiting, for the games of an owner */
    public function pendingByProject(User $owner): array
    {
        $rows = $this->createQueryBuilder('m')->select('IDENTITY(m.project) AS project, COUNT(m.id) AS n')
            ->join('m.project', 'p')->where('p.owner = :o')->andWhere('m.approved = false')->setParameter('o', $owner)
            ->groupBy('m.project')->getQuery()->getArrayResult();
        return array_column(array_map(fn ($r) => [(int)$r['project'], (int)$r['n']], $rows), 1, 0);
    }

    /** @return Comment[] the latest approved comments (ideas, issues) a user wrote, on games still shown */
    public function latestBy(User $author, int $limit = 10): array
    {
        return $this->withAuthors()->addSelect('p')->join('m.project', 'p')
            ->where('m.author = :u')->andWhere('m.approved = true')->andWhere('p.publishedSha IS NOT NULL')
            ->setParameter('u', $author)->orderBy('m.createdAt', 'DESC')->setMaxResults($limit)->getQuery()->getResult();
    }

    /** @return Comment[] the latest approved comments on the published games of an owner */
    public function latestOnGamesOf(User $owner, int $limit = 10): array
    {
        return $this->withAuthors()->addSelect('p')->join('m.project', 'p')
            ->where('p.owner = :u')->andWhere('m.approved = true')->andWhere('p.publishedSha IS NOT NULL')->andWhere('p.listed = true')
            ->setParameter('u', $owner)->orderBy('m.createdAt', 'DESC')->setMaxResults($limit)->getQuery()->getResult();
    }

    /**
     * The notifications of a game's owner: the messages (comments, ideas, issues) others left on their games,
     * newest first, by pages; with $isPendingOnly, those waiting for their approval.
     */
    public function notificationsOf(User $owner, bool $isPendingOnly = false, int $page = 1, int $perPage = 30): Paged
    {
        $qb = $this->notifications($owner)->addSelect('p')->leftJoin('m.author', 'a')->addSelect('a');
        if ($isPendingOnly) {
            $qb->andWhere('m.approved = false');
        }
        return Paged::of($qb->orderBy('m.createdAt', 'DESC')->addOrderBy('m.id', 'DESC'), $page, $perPage);
    }

    /** the bell's number: the messages new since the owner last looked, and those waiting for their approval */
    public function countNotifications(User $owner): int
    {
        $seen = $owner->getNotificationsSeenAt() ?? new \DateTimeImmutable('-30 days');
        return (int)$this->notifications($owner)->select('COUNT(m.id)')->andWhere('m.createdAt > :seen OR m.approved = false')
            ->setParameter('seen', $seen)->getQuery()->getSingleScalarResult();
    }

    private function notifications(User $owner): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('m')->join('m.project', 'p')->where('p.owner = :o')
            ->andWhere('m.author IS NULL OR m.author != :o')->setParameter('o', $owner);
    }

    /** how many comments came from an address since a moment (anti-flood) */
    public function countFrom(string $ipHash, \DateTimeImmutable $since): int
    {
        return (int)$this->createQueryBuilder('m')->select('COUNT(m.id)')
            ->where('m.ipHash = :h')->andWhere('m.createdAt > :t')->setParameter('h', $ipHash)->setParameter('t', $since)
            ->getQuery()->getSingleScalarResult();
    }
}
