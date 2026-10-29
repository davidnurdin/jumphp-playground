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
 * The site installable as an app (its manifest, its worker; icons in public/icons/), and each game on its own (a PWA): its manifest (start: the player /<slug>/jouer), its icons, and a
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
            'short_name' => $project->getAppName(),
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

    /** The site itself as an app (JumPHP playground on the home screen; base.html.twig). */
    #[Route('/manifest.webmanifest', name: 'site_manifest', methods: ['GET'])]
    public function siteManifest(TranslatorInterface $translator): JsonResponse
    {
        $icon = fn (string $name, int $size, string $purpose = 'any') => ['src' => "/icons/$name.png", 'sizes' => "{$size}x$size", 'type' => 'image/png', 'purpose' => $purpose];
        $response = new JsonResponse([
            'id' => '/',
            'name' => 'JumPHP playground',
            'short_name' => 'JumPHP',
            'description' => $translator->trans('Des jeux rétro écrits en PHP avec JumPHP, gratuits et jouables tout de suite dans le navigateur. Créez le vôtre en le décrivant à votre Claude.'),
            'start_url' => '/?app=1',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#0b0e1f',
            'theme_color' => '#0b0e1f',
            'categories' => ['games', 'entertainment'],
            'icons' => [$icon('icon-192', 192), $icon('icon-512', 512), $icon('maskable-512', 512, 'maskable')],
            'shortcuts' => [
                ['name' => $translator->trans('Jeux'), 'url' => $this->generateUrl('games')],
                ['name' => $translator->trans('Bac à sable'), 'url' => $this->generateUrl('sandbox')],
                ['name' => $translator->trans('Mes jeux'), 'url' => $this->generateUrl('studio')],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=3600', 'Vary' => 'Accept-Language, Cookie']);
        return $response->setEncodingOptions(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The Android apps this site lets show its pages full screen (Digital Asset Links): each game's app on Google
     * Play, made with JumPHP's `bin/jumphp twa` and declared by its creator (studio, MCP update_project).
     */
    #[Route('/.well-known/assetlinks.json', name: 'asset_links', methods: ['GET'])]
    public function assetLinks(): JsonResponse
    {
        $statements = array_map(fn (Project $p) => [
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => ['namespace' => 'android_app', 'package_name' => $p->getAndroidPackage(), 'sha256_cert_fingerprints' => $p->getAndroidFingerprints()],
        ], $this->projects->withAndroidApp());
        return (new JsonResponse($statements, 200, ['Cache-Control' => 'public, max-age=300']))->setEncodingOptions(JSON_UNESCAPED_SLASHES);
    }

    #[Route('/sw.js', name: 'site_sw', methods: ['GET'])]
    public function siteServiceWorker(TranslatorInterface $translator): Response
    {
        return $this->worker('/', $translator->trans('Pas de connexion : le site reviendra avec le réseau.'));
    }

    /** The game's worker: needed to install it; the pages always come from the network (the game caches its own files). */
    #[Route('/{slug}/sw.js', name: 'game_sw', requirements: ['slug' => self::SLUG], methods: ['GET'], priority: -5)]
    public function serviceWorker(string $slug, TranslatorInterface $translator): Response
    {
        $this->published($slug);
        return $this->worker("/$slug", $translator->trans('Pas de connexion : le jeu reviendra avec le réseau.'));
    }

    /** a worker that only makes an app installable: pages from the network; offline, $offline */
    private function worker(string $scope, string $offline): Response
    {
        $js = <<<'JS'
// The JumPHP playground (or one of its games), installed as an app: the pages come from the network; offline, a message.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));
self.addEventListener('fetch', (event) => {
	if (event.request.mode !== 'navigate') return;
	event.respondWith(fetch(event.request).catch(() => new Response(
		'<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><body style="background:#000;color:#fff;font:18px sans-serif;display:grid;place-items:center;height:100vh;margin:0;text-align:center">%offline%',
		{ headers: { 'Content-Type': 'text/html; charset=utf-8' } })));
});
JS;
        $js = strtr($js, ['%offline%' => htmlspecialchars($offline, ENT_QUOTES)]);
        return new Response($js, 200, [
            'Content-Type' => 'text/javascript; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'Service-Worker-Allowed' => $scope,
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

    private function published(string $slug): Project
    {
        $project = $this->projects->findOneBy(['slug' => $slug]);
        if (!$project || !$project->isPublished() || !$this->files->dir($project)) {
            throw new NotFoundHttpException();
        }
        return $project;
    }
}
