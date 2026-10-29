<?php

namespace App\Service;

use App\Repository\ProjectRepository;

/** A game's name in its URL (/{slug}), its GitHub repository and its folder games/<slug>. */
final class Slugs
{
    /** the portal's own paths, and words that would confuse */
    private const RESERVED = [
        'api', 'assets', 'admin', 'categorie', 'categories', 'connect', 'engine', 'favicon', 'jeux', 'jouer',
        'login', 'logout', 'mcp', 'media', 'play', 'robots', 'search', 'recherche', 'sitemap', 'source', 'studio',
        'webhook', 'www', 'bac-a-sable', 'sandbox', 'tutoriels', 'tutorials', 'note', 'help', 'aide', 'about', 'docs', 'static', 'new', 'edit', 'games', 'jumphp', 'skeleton',
    ];

    public function __construct(private ProjectRepository $projects)
    {
    }

    public static function fromTitle(string $title): string
    {
        $slug = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '-',
            (string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title)), '-'));
        $slug = ltrim($slug, '0123456789-');
        return substr($slug, 0, 40) ?: 'game';
    }

    /** Why this slug cannot be used, or null. */
    public function problem(string $slug): ?ProjectException
    {
        if (!preg_match('/^[a-z][a-z0-9-]{1,38}[a-z0-9]$/', $slug) || str_contains($slug, '--')) {
            return new ProjectException('Le nom doit faire 3 à 40 caractères : lettres minuscules, chiffres et tirets, en commençant par une lettre.');
        }
        if (in_array($slug, self::RESERVED, true)) {
            return new ProjectException('« %slug% » est réservé.', ['%slug%' => $slug]);
        }
        if ($this->projects->findOneBy(['slug' => $slug])) {
            return new ProjectException('« %slug% » est déjà pris.', ['%slug%' => $slug]);
        }
        return null;
    }

    public function available(string $wanted): string
    {
        $base = substr($wanted, 0, 36);
        for ($i = 0, $slug = $base; $this->problem($slug) !== null && $i < 50; $i++) {
            $slug = $base . '-' . ($i + 2);
        }
        return $slug;
    }
}
