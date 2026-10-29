<?php

namespace App\Controller;

use App\Repository\CategoryRepository;
use App\Repository\ProjectRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** The players' side: the newest games, the directory by category, search. */
final class HomeController extends AbstractController
{
    private const PER_PAGE = 24;

    public function __construct(private ProjectRepository $projects, private CategoryRepository $categories)
    {
    }

    #[Route('/', name: 'home', methods: ['GET'])]
    public function home(): Response
    {
        $categories = $this->categories->ordered();
        $latest = $this->projects->latest(12);
        // a shelf per category, without the games already shown in "new games" (each game once on the page)
        $shown = array_map(fn ($p) => $p->getId(), $latest);
        $shelves = [];
        foreach ($categories as $category) {
            $games = array_filter($this->projects->latest(6 + count($shown), $category), fn ($p) => !in_array($p->getId(), $shown, true));
            if ($games) {
                $shelves[] = ['category' => $category, 'games' => array_slice($games, 0, 6)];
            }
        }
        return $this->render('home/index.html.twig', [
            'latest' => $latest,
            'shelves' => $shelves,
            'categories' => $categories,
            'counts' => $this->categories->counts(),
            'total' => $this->projects->countListed(),
        ]);
    }

    /** Every game at once: a wall of pictures and names, sorted by mark, plays, date or name. */
    #[Route('/games', name: 'games', methods: ['GET'])]
    public function games(Request $request): Response
    {
        $sort = (string)$request->query->get('tri', 'note');
        $sort = isset(ProjectRepository::SORTS[$sort]) ? $sort : 'note';
        return $this->render('home/games.html.twig', ['games' => $this->projects->sorted($sort), 'sort' => $sort, 'sorts' => ProjectRepository::SORTS]);
    }

    #[Route('/jeux', name: 'games_fr', methods: ['GET'])]
    public function gamesFr(): Response
    {
        return $this->redirectToRoute('games', [], 301);
    }

    #[Route('/categories', name: 'categories', methods: ['GET'])]
    public function categories(): Response
    {
        return $this->render('home/categories.html.twig', [
            'categories' => $this->categories->ordered(),
            'counts' => $this->categories->counts(),
        ]);
    }

    #[Route('/categorie/{slug}', name: 'category', methods: ['GET'])]
    public function category(string $slug, Request $request): Response
    {
        $category = $this->categories->findOneBy(['slug' => $slug]) ?? throw new NotFoundHttpException();
        $page = max(1, $request->query->getInt('page', 1));
        $total = $this->projects->countListed($category);
        return $this->render('home/category.html.twig', [
            'category' => $category,
            'categories' => $this->categories->ordered(),
            'games' => $this->projects->latest(self::PER_PAGE, $category, ($page - 1) * self::PER_PAGE),
            'page' => $page,
            'pages' => max(1, (int)ceil($total / self::PER_PAGE)),
            'total' => $total,
        ]);
    }

    #[Route('/recherche', name: 'search', methods: ['GET'])]
    public function search(Request $request): Response
    {
        $q = mb_substr(trim((string)$request->query->get('q')), 0, 80);
        return $this->render('home/search.html.twig', [
            'q' => $q,
            'games' => $q !== '' ? $this->projects->search($q) : [],
            'categories' => $this->categories->ordered(),
        ]);
    }

    #[Route('/robots.txt', name: 'robots', methods: ['GET'])]
    public function robots(): Response
    {
        $sitemap = $this->generateUrl('sitemap', [], 0);
        return new Response("User-agent: *\nDisallow: /studio\nDisallow: /api/\nDisallow: /connect/\nDisallow: /*/edit\nAllow: /\n\nSitemap: $sitemap\n",
            200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    #[Route('/sitemap.xml', name: 'sitemap', methods: ['GET'])]
    public function sitemap(): Response
    {
        $response = $this->render('home/sitemap.xml.twig', [
            'games' => $this->projects->latest(5000),
            'categories' => $this->categories->ordered(),
            'docs' => array_merge(...array_values(array_map(array_keys(...), DocsController::PAGES))),
        ]);
        $response->headers->set('Content-Type', 'application/xml; charset=utf-8');
        return $response;
    }
}
