<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation;
use App\State\GameProvider;

/** A game of the directory (listed and published), in the public API. */
#[ApiResource(
    shortName: 'Game',
    description: 'Un jeu publié dans l\'annuaire.',
    operations: [
        new GetCollection(
            uriTemplate: '/games',
            openapi: new Operation(summary: 'Les jeux', description: 'Les jeux publiés, 30 par page (itemsPerPage jusqu\'à 100), filtrés et triés. En JSON-LD (Accept: application/ld+json) : totalItems et les pages suivantes (view.next).'),
            parameters: [
                'category' => new QueryParameter(schema: ['type' => 'string'], description: 'Une catégorie (son slug, voir /api/categories).'),
                'kind' => new QueryParameter(schema: ['type' => 'string', 'enum' => ['original', 'cpc', 'port']], description: 'original : création ; cpc : remake Amstrad CPC ; port : portage.'),
                'order[rating]' => new QueryParameter(schema: ['type' => 'string', 'enum' => ['asc', 'desc']], description: 'Par note (moyenne tirée vers 3 tant qu\'il y a peu de votes).'),
                'order[plays]' => new QueryParameter(schema: ['type' => 'string', 'enum' => ['asc', 'desc']], description: 'Par nombre de parties.'),
                'order[publishedAt]' => new QueryParameter(schema: ['type' => 'string', 'enum' => ['asc', 'desc']], description: 'Par date de publication (le tri par défaut, les plus récents d\'abord).'),
                'order[title]' => new QueryParameter(schema: ['type' => 'string', 'enum' => ['asc', 'desc']], description: 'Par titre.'),
            ],
        ),
        new Get(uriTemplate: '/games/{slug}', openapi: new Operation(summary: 'Un jeu')),
    ],
    provider: GameProvider::class,
)]
final class Game
{
    #[ApiProperty(identifier: true, description: 'Son identifiant (dans ses adresses).')]
    public string $slug = '';

    public string $title = '';

    public string $description = '';

    #[ApiProperty(description: 'Sa catégorie (slug).')]
    public string $category = '';

    #[ApiProperty(description: 'original, cpc (remake Amstrad CPC) ou port (portage).')]
    public string $kind = 'original';

    #[ApiProperty(description: 'Son auteur (identifiant GitHub).')]
    public string $author = '';

    #[ApiProperty(description: 'Sa note moyenne sur 5, null sans vote.')]
    public ?float $rating = null;

    #[ApiProperty(description: 'Le nombre de votes.')]
    public int $ratings = 0;

    #[ApiProperty(description: 'Le nombre de parties lancées.')]
    public int $plays = 0;

    #[ApiProperty(description: 'La version du moteur JumPHP qui le fait tourner.')]
    public ?string $engine = null;

    public ?\DateTimeImmutable $publishedAt = null;

    #[ApiProperty(description: 'Le jeu dont il est un fork (slug), sinon null.')]
    public ?string $forkOf = null;

    #[ApiProperty(description: 'Sa page.')]
    public string $url = '';

    #[ApiProperty(description: 'Le jeu en plein écran.')]
    public string $play = '';

    #[ApiProperty(description: 'Son code source, navigable.')]
    public string $source = '';

    #[ApiProperty(description: 'Son image (640 × 360 PNG), null s\'il n\'en a pas.')]
    public ?string $image = null;

    #[ApiProperty(description: 'Son dépôt GitHub.')]
    public string $repository = '';

    #[ApiProperty(description: 'La liste de ses fichiers publiés (mode distant de JumPHP).')]
    public string $manifest = '';
}
