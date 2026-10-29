<?php

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Category;
use App\Entity\Category as CategoryEntity;
use App\Repository\CategoryRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The categories of the public API, with how many games each has.
 *
 * @implements ProviderInterface<Category>
 */
final class CategoryProvider implements ProviderInterface
{
    public function __construct(
        private CategoryRepository $categories,
        private UrlGeneratorInterface $urls,
        private string $appUrl,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $counts = $this->categories->counts();
        if (!$operation instanceof CollectionOperationInterface) {
            $category = $this->categories->findOneBy(['slug' => (string)($uriVariables['slug'] ?? '')]);
            return $category ? $this->category($category, $counts) : null;
        }
        return array_map(fn (CategoryEntity $c) => $this->category($c, $counts), $this->categories->ordered());
    }

    /** @param array<int, int> $counts */
    private function category(CategoryEntity $c, array $counts): Category
    {
        $category = new Category();
        $category->slug = $c->getSlug();
        $category->name = $c->getName();
        $category->description = $c->getDescription();
        $category->games = (int)($counts[$c->getId()] ?? 0);
        $category->url = rtrim($this->appUrl, '/') . $this->urls->generate('category', ['slug' => $c->getSlug()]);
        return $category;
    }
}
