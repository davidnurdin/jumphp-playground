<?php

namespace App\Repository;

use App\Entity\GameDay;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\Request;

/** @extends ServiceEntityRepository<GameDay> */
final class GameDayRepository extends ServiceEntityRepository
{
    public const FIELDS = ['visits', 'plays', 'launches'];
    private const ROBOT = '/bot|crawl|spider|slurp|preview|facebookexternalhit|headless|lighthouse|curl|wget|python|go-http|java\//i';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GameDay::class);
    }

    /** One more $field (visits, plays, launches) today for $project; a robot's request does not count. */
    public function tally(Project $project, string $field, ?Request $request = null): void
    {
        if (!in_array($field, self::FIELDS, true) || ($request && preg_match(self::ROBOT, (string)$request->headers->get('User-Agent', 'robot')))) {
            return;
        }
        $this->getEntityManager()->getConnection()->executeStatement(
            "INSERT INTO game_day (project_id, day, visits, plays, launches) VALUES (?, CURRENT_DATE, ?, ?, ?)
             ON DUPLICATE KEY UPDATE $field = $field + 1",
            [$project->getId(), (int)($field === 'visits'), (int)($field === 'plays'), (int)($field === 'launches')],
        );
    }

    /**
     * The last $days days, today last, the days without anything included.
     *
     * @return list<array{day: \DateTimeImmutable, visits: int, plays: int, launches: int}>
     */
    public function lastDays(Project $project, int $days = 30): array
    {
        $from = new \DateTimeImmutable('today -' . ($days - 1) . ' days');
        $rows = [];
        foreach ($this->createQueryBuilder('d')->where('d.project = :p AND d.day >= :from')
            ->setParameter('p', $project)->setParameter('from', $from, 'date_immutable')->getQuery()->getResult() as $row) {
            $rows[$row->getDay()->format('Y-m-d')] = $row;
        }
        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $from->modify("+$i days");
            $row = $rows[$day->format('Y-m-d')] ?? null;
            $out[] = ['day' => $day, 'visits' => $row?->getVisits() ?? 0, 'plays' => $row?->getPlays() ?? 0, 'launches' => $row?->getLaunches() ?? 0];
        }
        return $out;
    }

    /** @return array{visits: int, plays: int, launches: int} since the statistics exist */
    public function totals(Project $project): array
    {
        $row = $this->createQueryBuilder('d')->select('COALESCE(SUM(d.visits), 0) AS visits, COALESCE(SUM(d.plays), 0) AS plays, COALESCE(SUM(d.launches), 0) AS launches')
            ->where('d.project = :p')->setParameter('p', $project)->getQuery()->getSingleResult();
        return array_map('intval', $row);
    }
}
