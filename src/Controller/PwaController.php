<?php

namespace App\Controller;

use App\Entity\Project;
use App\Repository\ProjectRepository;
use App\Service\GameFiles;
use App\Service\Thumbnailer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Each game installable on its own (a PWA): its manifest (start: the player /<slug>/jouer), its icons, and a
 * service worker whose scope is the game's address. The install button: templates/game/_install.html.twig.
 */
#[Route(condition: "request.getHost() != env('PLAY_HOST')")]
final class PwaController extends AbstractController
{
    private const SLUG = '[a-z][a-z0-9-]{1,38}[a-z0-9]';

    public function __construct(private ProjectRepository $projects, private GameFiles $files)
    {
    }

    #[Route('/{slug}/manifest.webmanifest', name: 'game_manifest', requirements: ['slug' => self::SLUG], methods: ['GET'], priority: -5)]
    public function manifest(string $slug): JsonResponse
    {
        $project = $this->published($slug);
        $info = $project->getGameInfo();
        $icon = fn (int $size) => ['src' => $this->generateUrl('game_icon', ['slug' => $slug, 'sha' => substr((string)$project->getPublishedSha(), 0, 12), 'size' => $size]),
            'sizes' => "{$size}x$size", 'type' => 'image/png', 'purpose' => 'any'];
        $landscape = (int)($info['width'] ?? 320) >= (int)($info['height'] ?? 180);
        $response = new JsonResponse([
            'id' => "/$slug",
            'name' => $project->getTitle(),
            'short_name' => self::shortName($project->getTitle()),
            'description' => $project->getDescription(),
            'lang' => 'fr',
            'start_url' => "/$slug/jouer?app=1",
            'scope' => "/$slug",
            'display' => 'fullscreen',
            'display_override' => ['fullscreen', 'standalone'],
            'orientation' => $landscape ? 'landscape' : 'portrait',
            'background_color' => '#000000',
            'theme_color' => '#0b0e1f',
            'categories' => ['games', 'entertainment'],
            'icons' => [$icon(192), $icon(512)],
        ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=300']);
        return $response->setEncodingOptions(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** The game's worker: needed to install it; the pages always come from the network (the game caches its own files). */
    #[Route('/{slug}/sw.js', name: 'game_sw', requirements: ['slug' => self::SLUG], methods: ['GET'], priority: -5)]
    public function serviceWorker(string $slug, TranslatorInterface $translator): Response
    {
        $this->published($slug);
        $js = <<<'JS'
// A game of the JumPHP playground, installed as an app: the pages come from the network; offline, a message.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));
self.addEventListener('fetch', (event) => {
	if (event.request.mode !== 'navigate') return;
	event.respondWith(fetch(event.request).catch(() => new Response(
		'<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><body style="background:#000;color:#fff;font:18px sans-serif;display:grid;place-items:center;height:100vh;margin:0;text-align:center">%offline%',
		{ headers: { 'Content-Type': 'text/html; charset=utf-8' } })));
});
JS;
        $js = strtr($js, ['%offline%' => htmlspecialchars($translator->trans('Pas de connexion : le jeu reviendra avec le réseau.'), ENT_QUOTES)]);
        return new Response($js, 200, [
            'Content-Type' => 'text/javascript; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'Service-Worker-Allowed' => "/$slug",
        ]);
    }

    #[Route('/media/icons/{slug}-{sha}-{size}.png', name: 'game_icon', requirements: ['slug' => self::SLUG, 'sha' => '[0-9a-f]{7,40}', 'size' => '192|512|180'], methods: ['GET'])]
    public function icon(string $slug, int $size, Thumbnailer $thumbnailer): BinaryFileResponse
    {
        $project = $this->published($slug);
        $dir = (string)$this->files->dir($project);
        $out = "$dir/icon-$size.png";
        if (!is_file($out) && !$thumbnailer->icon("$dir/game/assets/icon.png", $this->files->thumbnail($project), $size, $out, $project->getTitle())) {
            throw new NotFoundHttpException();
        }
        $response = new BinaryFileResponse($out);
        $response->headers->set('Content-Type', 'image/png');
        $response->headers->set('Cache-Control', 'public, max-age=31536000, immutable');
        return $response;
    }

    /** the name under the icon: whole words, 15 characters at most */
    private static function shortName(string $title): string
    {
        $name = '';
        foreach (preg_split('/\s+/', trim($title)) ?: [] as $word) {
            if (mb_strlen(trim("$name $word")) > 15) {
                break;
            }
            $name = trim("$name $word");
        }
        return $name !== '' ? $name : mb_substr($title, 0, 15);
    }

    private function published(string $slug): Project
    {
        $project = $this->projects->findOneBy(['slug' => $slug]);
        if (!$project || !$project->isPublished() || !$this->files->dir($project)) {
            throw new NotFoundHttpException();
        }
        return $project;
    }
}
