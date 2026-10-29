<?php

namespace App\Controller;

use App\Entity\Project;
use App\Repository\DraftRepository;
use App\Repository\ProjectRepository;
use App\Repository\UserRepository;
use App\Service\DraftAuthor;
use App\Service\DraftToken;
use App\Service\GameFiles;
use App\Service\PrivateFiles;
use App\Service\Runtimes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The games origin (PLAY_URL): where the games' code and sites run, with no session nor cookie of the portal.
 *   /<slug>/                the game's own web site (site/), else the game
 *   /<slug>/play/           the game: JumPHP's page with the shared engine /engine/<version>/ (remote mode);
 *                           ?version=<sha>: an older version of it (extracted first by the portal: Publisher::version())
 *   /<slug>/play/manifest.json, /<slug>/play/files/<path>   its files, one by one
 *   /<slug>/play/draft/<token>/<path>                      a user's draft (the online editor)
 *   /<slug>/play/private/<path>                            a file given by the owner, in no repository (PrivateFiles)
 *   /<slug>/raw/<path>      any published file of the game, as text (the source browser)
 */
#[Route(condition: "request.getHost() == env('PLAY_HOST')")]
final class PlayController extends AbstractController
{
    private const SITE_TEXT = '/\.(html?|xml|txt|json|webmanifest)$/i';

    public function __construct(
        private ProjectRepository $projects,
        private GameFiles $files,
        private Runtimes $runtimes,
        private DraftToken $tokens,
        private DraftRepository $drafts,
        private UserRepository $users,
        private PrivateFiles $private,
        private string $appUrl,
    ) {
    }

    #[Route('/', name: 'play_root', priority: 10)]
    public function root(): Response
    {
        return new RedirectResponse($this->appUrl);
    }

    #[Route('/{slug}/play/', name: 'play_game', requirements: ['slug' => '[a-z][a-z0-9-]+'], methods: ['GET'], priority: 10)]
    public function game(string $slug, Request $request): Response
    {
        $project = $this->published($slug);
        $sha = $this->version($project, $request);
        $draft = (string)$request->query->get('draft');
        $isDraft = $sha === null && $draft !== '' && $this->tokens->read($draft, $project->getId()) !== null;
        // the editor's preview: the newest engine (hot reload, the console), whatever the published game asked for
        $engine = $this->runtimes->pick($isDraft ? null : ($sha ? (string)($this->files->manifest($project, $sha)['engine'] ?? '') : $project->getEngineVersion()));
        if (!$engine) {
            throw new NotFoundHttpException('No engine');
        }
        $info = $project->getGameInfo();
        $runtime = $this->runtimes->info($engine);
        $manifest = 'manifest.json' . match (true) {
            $sha !== null => '?version=' . $sha,
            $isDraft => '?draft=' . rawurlencode($draft),
            default => '',
        };
        $hasIcon = (bool)$this->files->path($project, 'assets/icon.png');
        $game = array_intersect_key($info, array_flip(['id', 'title', 'version', 'description', 'width', 'height', 'online', 'lang', 'net_url', 'touch', 'controls']));
        $h = fn (string $s) => htmlspecialchars($s, ENT_QUOTES);
        $html = strtr($runtime['page'], [
            '{{TITLE}}' => $h((string)($info['title'] ?? $project->getTitle())),
            '{{DESCRIPTION}}' => $h((string)($info['description'] ?? '')),
            '{{LANG}}' => $h((string)($info['lang'] ?? 'en')),
            '{{WIDTH}}' => (string)(int)($info['width'] ?? 320),
            '{{HEIGHT}}' => (string)(int)($info['height'] ?? 180),
            '{{ICON}}' => $hasIcon ? 'files/assets/icon.png' : 'data:,',
            '{{PWA_HEAD}}' => '',
            '{{ENGINE}}' => '/engine/' . $engine . '/',
            '{{ENGINE_VERSION}}' => $h((string)($runtime['runtime']['php'] ?? '')),
            '{{WASM_SIZE}}' => (string)(int)($runtime['runtime']['wasm_size'] ?? 0),
            '{{GAME_JSON}}' => json_encode($game + ['remote' => ['manifest' => $manifest]], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS),
            '{{V_JS}}' => $runtime['hashes']['jumphp.js'] ?? '0',
            '{{V_TOUCH}}' => $runtime['hashes']['touch.js'] ?? '0',
            '{{V_NET}}' => $runtime['hashes']['php-wasm-network.js'] ?? '0',
        ]);
        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-cache']);
    }

    #[Route('/{slug}/play/manifest.json', name: 'play_manifest', requirements: ['slug' => '[a-z][a-z0-9-]+'], methods: ['GET'], priority: 10)]
    public function manifest(string $slug, Request $request): Response
    {
        $project = $this->published($slug);
        $token = (string)$request->query->get('draft');
        $author = $this->author($token !== '' ? $this->tokens->read($token, $project->getId()) : null);
        $manifest = $author
            ? $this->files->manifestWithDrafts($project, $this->drafts->of($author, $project), $token)
            : $this->files->manifest($project, $this->version($project, $request));
        if (!$manifest) {
            throw new NotFoundHttpException();
        }
        return new JsonResponse($manifest, 200, ['Cache-Control' => 'no-store']);
    }

    #[Route('/{slug}/play/files/{path}', name: 'play_file', requirements: ['slug' => '[a-z][a-z0-9-]+', 'path' => '.+'], methods: ['GET'], priority: 10)]
    public function gameFile(string $slug, string $path, Request $request): Response
    {
        $project = $this->published($slug);
        $wanted = (string)$request->query->get('v');
        // ?v=<hash>: the version of the manifest the page loaded (current or the previous one)
        foreach ($wanted !== '' ? $this->versions($project) : [null] as $sha) {
            $dir = $this->files->dir($project, $sha);
            $file = $dir ? $dir . '/game/' . GameFiles::clean($path) : null;
            if (!$file || !is_file($file) || is_link($file) || preg_match(GameFiles::NOT_LOADED, (string)GameFiles::clean($path))) {
                continue;
            }
            if ($wanted !== '' && substr(hash_file('sha256', $file), 0, 16) !== $wanted) {
                continue;
            }
            return $this->send($file, $wanted !== '');
        }
        throw new NotFoundHttpException();
    }

    #[Route('/{slug}/play/draft/{token}/{path}', name: 'play_draft', requirements: ['slug' => '[a-z][a-z0-9-]+', 'path' => '.+'], methods: ['GET'], priority: 10)]
    public function draft(string $slug, string $token, string $path): Response
    {
        $project = $this->published($slug);
        $author = $this->author($this->tokens->read($token, $project->getId()));
        $draft = $author ? $this->drafts->forPath($author, $project, (string)GameFiles::clean($path)) : null;
        if (!$draft || $draft->isDeleted()) {
            throw new NotFoundHttpException();
        }
        // anyone's text (the sandbox): bytes for the game, never a page of this origin
        return new Response((string)$draft->getContent(), 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }

    #[Route('/{slug}/play/private/{path}', name: 'play_private', requirements: ['slug' => '[a-z][a-z0-9-]+', 'path' => '.+'], methods: ['GET'], priority: 10)]
    public function privateFile(string $slug, string $path): Response
    {
        $file = $this->private->path($this->published($slug), $path) ?? throw new NotFoundHttpException();
        return $this->send($file, true);
    }

    #[Route('/{slug}/raw/{path}', name: 'play_raw', requirements: ['slug' => '[a-z][a-z0-9-]+', 'path' => '.+'], methods: ['GET'], priority: 10)]
    public function raw(string $slug, string $path): Response
    {
        $file = $this->files->path($this->published($slug), $path) ?? throw new NotFoundHttpException();
        $response = new BinaryFileResponse($file);
        $mime = GameFiles::mime($path);
        // source code is shown, never run, even here
        $response->headers->set('Content-Type', str_starts_with($mime, 'text/') || str_contains($mime, 'json') || str_contains($mime, 'xml') || str_contains($mime, 'svg')
            ? 'text/plain; charset=utf-8' : $mime);
        $response->headers->set('Cache-Control', 'public, max-age=300');
        return $response;
    }

    /** The game's web site; without one, the game. */
    #[Route('/{slug}/{path}', name: 'play_site', requirements: ['slug' => '[a-z][a-z0-9-]+', 'path' => '.*'], methods: ['GET'], priority: 0)]
    public function site(string $slug, string $path = ''): Response
    {
        $project = $this->published($slug);
        $dir = $this->files->dir($project);
        if (!$project->hasSite() || !$dir) {
            return new RedirectResponse("/$slug/play/");
        }
        $path = GameFiles::clean($path) ?? 'index.html';
        if (str_starts_with($path, 'play/') || $path === 'play') {
            return new RedirectResponse("/$slug/play/");
        }
        $file = "$dir/game/site/$path";
        if (is_dir($file)) {
            $file .= '/index.html';
        }
        if (!is_file($file) || is_link($file)) {
            throw new NotFoundHttpException();
        }
        if (preg_match(self::SITE_TEXT, $file)) {
            $text = str_replace('{{DOMAIN}}', $this->portalDomain($project), (string)file_get_contents($file));
            return new Response($text, 200, ['Content-Type' => GameFiles::mime($file), 'Cache-Control' => 'no-cache']);
        }
        return $this->send($file, false);
    }

    /** @param array{project: int, author: string}|null $claim a preview token's */
    private function author(?array $claim): ?DraftAuthor
    {
        if (!$claim) {
            return null;
        }
        if ($claim['author'][0] === 'v') {
            return DraftAuthor::visitor(substr($claim['author'], 1));
        }
        $user = $this->users->find((int)substr($claim['author'], 1));
        return $user ? DraftAuthor::user($user) : null;
    }

    /** {{DOMAIN}} of a game's site on the playground: the game's page on the portal */
    private function portalDomain(Project $project): string
    {
        return parse_url($this->appUrl, PHP_URL_HOST) . (($port = parse_url($this->appUrl, PHP_URL_PORT)) ? ":$port" : '') . '/' . $project->getSlug();
    }

    private function published(string $slug): Project
    {
        $project = $this->projects->findOneBy(['slug' => $slug]);
        if (!$project || !$project->isPublished()) {
            throw new NotFoundHttpException();
        }
        return $project;
    }

    /** ?version=<sha>: an older version of the game, when the portal extracted it (else none: the newest) */
    private function version(Project $project, Request $request): ?string
    {
        $sha = (string)$request->query->get('version');
        return preg_match('/^[0-9a-f]{40}$/', $sha) && $sha !== $project->getPublishedSha() && $this->files->dir($project, $sha) ? $sha : null;
    }

    /** @return string[] the published versions, newest first */
    private function versions(Project $project): array
    {
        $dirs = glob(dirname((string)$this->files->dir($project)) . '/*', GLOB_ONLYDIR) ?: [];
        $dirs = array_filter($dirs, fn ($d) => !str_contains(basename($d), '.tmp-'));
        usort($dirs, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        return array_map('basename', $dirs);
    }

    private function send(string $file, bool $immutable): BinaryFileResponse
    {
        $response = new BinaryFileResponse($file);
        $response->headers->set('Content-Type', GameFiles::mime($file));
        $response->headers->set('Cache-Control', $immutable ? 'public, max-age=31536000, immutable' : 'public, max-age=60');
        return $response;
    }
}
