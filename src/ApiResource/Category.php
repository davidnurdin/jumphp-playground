<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model\Operation;
use App\State\CategoryProvider;

/** A category of the directory, in the public API. */
#[ApiResource(
    shortName: 'Category',
    description: 'Une catégorie de l\'annuaire.',
    operations: [
        new GetCollection(uriTemplate: '/categories', paginationEnabled: false, openapi: new Operation(summary: 'Les catégories', description: 'Toutes les catégories, dans l\'ordre du site, avec leur nombre de jeux.')),
        new Get(uriTemplate: '/categories/{slug}', openapi: new Operation(summary: 'Une catégorie')),
    ],
    provider: CategoryProvider::class,
)]
final class Category
{
    #[ApiProperty(identifier: true, description: 'Son identifiant (le filtre category de /api/games).')]
    public string $slug = '';

    public string $name = '';

    public string $description = '';

    #[ApiProperty(description: 'Le nombre de jeux publiés dedans.')]
    public int $games = 0;

    #[ApiProperty(description: 'Sa page.')]
    public string $url = '';
}
