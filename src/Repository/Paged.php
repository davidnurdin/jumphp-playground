<?php

namespace App\Repository;

use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;

/** One page of a query: its items, the page (1 to pages) and how many pages (templates/_pager.html.twig). */
final class Paged
{
    /** @param list<mixed> $items */
    private function __construct(public readonly array $items, public readonly int $page, public readonly int $pages, public readonly int $total)
    {
    }

    public static function of(QueryBuilder $qb, int $page, int $perPage): self
    {
        $paginator = new Paginator($qb, fetchJoinCollection: false);
        $total = count($paginator);
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        // the paginator's own query (it took the builder's at its creation)
        $paginator->getQuery()->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage);
        return new self(iterator_to_array($paginator, false), $page, $pages, $total);
    }
}
