<?php

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Game;
use App\Entity\Project;
use App\Repository\ProjectRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The games of the public API: the listed, published projects.
 *
 * @implements ProviderInterface<Game>
 */
final class GameProvider implements ProviderInterface
{
    public function __construct(
        private ProjectRepository $projects,
        private Pagination $pagination,
        private UrlGeneratorInterface $urls,
        private string $appUrl,
        private string $playUrl,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if (!$operation instanceof CollectionOperationInterface) {
            $project = $this->projects->findOneBy(['slug' => (string)($uriVariables['slug'] ?? '')]);
            return $project && $project->isListed() && $project->isPublished() ? $this->game($project) : null;
        }
        $filters = $context['filters'] ?? [];
        $order = [];
        foreach ((array)($filters['order'] ?? []) as $field => $direction) {
            if (in_array($field, ProjectRepository::API_ORDERS, true)) {
                $order[$field] = is_string($direction) && strtolower($direction) === 'asc' ? 'ASC' : 'DESC';
            }
        }
        $limit = $this->pagination->getLimit($operation, $context);
        [$projects, $total] = $this->projects->forApi(
            is_string($filters['category'] ?? null) ? $filters['category'] : null,
            is_string($filters['kind'] ?? null) ? $filters['kind'] : null,
            $order ?: ['publishedAt' => 'DESC'],
            $this->pagination->getOffset($operation, $context),
            $limit,
        );
        return new TraversablePaginator(new \ArrayIterator(array_map($this->game(...), $projects)), $this->pagination->getPage($context), $limit, $total);
    }

    private function game(Project $p): Game
    {
        $app = fn (string $route, array $params = []) => rtrim($this->appUrl, '/') . $this->urls->generate($route, ['slug' => $p->getSlug()] + $params);
        $game = new Game();
        $game->slug = $p->getSlug();
        $game->title = $p->getTitle();
        $game->description = $p->getDescription();
        $game->category = $p->getCategory()->getSlug();
        $game->kind = $p->getKind();
        $game->author = $p->getOwner()->getLogin();
        $game->rating = $p->getRating();
        $game->ratings = $p->getRatingCount();
        $game->plays = $p->getPlays();
        $game->engine = $p->getEngineVersion();
        $game->publishedAt = $p->getPublishedAt();
        $game->forkOf = $p->getForkOf()?->getSlug();
        $game->url = $app('game');
        $game->play = $app('game_play');
        $game->source = $app('game_source');
        $game->image = $p->hasThumbnail() ? $app('game_thumb', ['sha' => substr((string)$p->getPublishedSha(), 0, 12)]) : null;
        $game->repository = $p->getRepoUrl();
        $game->manifest = rtrim($this->playUrl, '/') . $this->urls->generate('play_manifest', ['slug' => $p->getSlug()]);
        return $game;
    }
}
