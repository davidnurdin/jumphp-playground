<?php

namespace App\Controller;

use League\CommonMark\GithubFlavoredMarkdownConverter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** The documentation: every way to make a game, the tutorials (content/docs/<page>.md, GitHub Markdown; content/docs/en/<page>.md in English, else the French page). */
#[Route(condition: "request.getHost() != env('PLAY_HOST')")]
final class DocsController extends AbstractController
{
    /** the pages, in the order of the menu: group => [page => title] (French, translated in the template) */
    public const PAGES = [
        'Créer un jeu' => [
            'index' => 'Toutes les façons',
            'bac-a-sable' => 'Le bac à sable (sans compte)',
            'avec-claude' => 'Avec votre Claude',
            'panneau-claude' => 'Le panneau JumPHP de Claude Code',
            'editeur-en-ligne' => 'L\'éditeur en ligne',
            'en-local' => 'Sur votre ordinateur',
            'importer' => 'Importer un dépôt',
            'remake-amstrad' => 'Remake Amstrad CPC',
            'portage' => 'Porter un jeu existant',
            'tests' => 'Tester son jeu (PHPUnit)',
            'android' => 'Sur Google Play (Android)',
            'executable' => 'Un exécutable (Linux, Windows)',
        ],
        'Tutoriels' => [
            'hello-world' => '1. Hello world',
            'personnage' => '2. Un personnage',
            'plates-formes' => '3. Plates-formes',
            'sons' => '4. Sons et musique',
            'menus' => '5. Menus et pause',
            'sauvegarde' => '6. Sauvegarde',
            'multijoueur' => '7. Multijoueur',
            'page-du-jeu' => '8. Page du jeu',
        ],
        'Pour les programmes' => [
            'api' => 'L\'API publique',
        ],
    ];

    #[Route('/docs', name: 'docs', methods: ['GET'])]
    #[Route('/docs/{page}', name: 'docs_page', requirements: ['page' => '[a-z0-9-]+'], methods: ['GET'])]
    public function page(Request $request, string $page = 'index'): Response
    {
        $order = array_merge(...array_values(array_map(fn (array $group) => array_keys($group), self::PAGES)));
        $dir = $this->getParameter('kernel.project_dir') . '/content/docs';
        $file = "$dir/$page.md";
        if ($request->getLocale() === 'en' && is_file("$dir/en/$page.md")) {
            $file = "$dir/en/$page.md";
        }
        $at = array_search($page, $order, true);
        if ($at === false || !is_file($file)) {
            throw new NotFoundHttpException();
        }
        $markdown = (string)file_get_contents($file);
        $html = (string)(new GithubFlavoredMarkdownConverter(['html_input' => 'allow', 'allow_unsafe_links' => false]))->convert($markdown);
        // each section can be linked to (#garder-letat-dune-scène): its title, lower case, spaces as dashes
        $html = (string)preg_replace_callback('#<h([23])>(.+?)</h\1>#u', static function (array $m): string {
            $id = trim((string)preg_replace(['/<[^>]+>|&#?\w+;|[^\p{L}\p{N} -]/u', '/ /'], ['', '-'], mb_strtolower(html_entity_decode($m[2], ENT_QUOTES))), '-');
            return "<h$m[1] id=\"" . htmlspecialchars($id) . "\">$m[2]</h$m[1]>";
        }, $html);
        preg_match('/^# (.+)$/m', $markdown, $title);
        preg_match('/^(?!#)(?!\|)(?!```)(\S.+)$/m', $markdown, $first);
        $titles = array_merge(...array_values(self::PAGES));
        return $this->render('docs/page.html.twig', [
            'page' => $page,
            'title' => $title[1] ?? $titles[$page],
            'description' => mb_substr(strip_tags((string)preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $first[1] ?? '')), 0, 200),
            'html' => $html,
            'pages' => self::PAGES,
            'previous' => $at > 0 ? ['page' => $order[$at - 1], 'title' => $titles[$order[$at - 1]]] : null,
            'next' => $at < count($order) - 1 ? ['page' => $order[$at + 1], 'title' => $titles[$order[$at + 1]]] : null,
        ]);
    }
}
