<?php

namespace App\Controller;

use App\Entity\Project;
use App\Entity\User;
use App\GitHub\GitHub;
use App\Repository\DraftRepository;
use App\Repository\ProjectRepository;
use App\Repository\RatingRepository;
use App\Security\TokenCipher;
use App\Service\GameFiles;
use App\Service\ProjectException;
use App\Service\Projects;
use App\Service\Slugs;
use App\Service\Voters;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A game on the portal (<APP_URL>/<slug>): its page (its own site in a frame of the games origin, or
 * the portal's page), the player, the sources, the online editor, the fork. These routes come after every
 * other route of the portal (priority -10).
 */
#[Route(condition: "request.getHost() != env('PLAY_HOST')")]
final class GameController extends AbstractController
{
    private const SLUG = '[a-z][a-z0-9-]{1,38}[a-z0-9]';

    public function __construct(
        private ProjectRepository $projects,
        private GameFiles $files,
        private string $playUrl,
    ) {
    }

    #[Route('/media/thumbs/{slug}-{sha}.png', name: 'game_thumb', requirements: ['slug' => self::SLUG, 'sha' => '[0-9a-f]{7,40}'], methods: ['GET'])]
    public function thumb(string $slug): Response
    {
        $project = $this->projects->findOneBy(['slug' => $slug]);
        $file = $project ? $this->files->thumbnail($project) : null;
        if (!$file) {
            throw new NotFoundHttpException();
        }
        $response = new BinaryFileResponse($file);
        $response->headers->set('Content-Type', 'image/png');
        $response->headers->set('Cache-Control', 'public, max-age=31536000, immutable');
        return $response;
    }

    #[Route('/{slug}', name: 'game', requirements: ['slug' => self::SLUG], methods: ['GET'], priority: -10)]
    public function show(string $slug, #[CurrentUser] ?User $user, Slugs $slugs, Request $request, RatingRepository $ratings, Voters $voters): Response
    {
        $project = $this->visible($slug, $user);
        return $this->render('game/show.html.twig', [
            'project' => $project,
            'my_rating' => $ratings->scoreOf($project, $voters->of($request, $user)),
            'fork_slug' => $user && !$project->isOwnedBy($user) ? $slugs->available(Slugs::fromTitle($project->getSlug() . '-' . $user->getLogin())) : null,
            'frame' => $project->hasSite() ? $this->play($project) . '/' : null,
            'others' => array_filter($this->projects->latest(7, $project->getCategory()), fn ($p) => $p->getId() !== $project->getId()),
        ]);
    }

    #[Route('/{slug}/jouer', name: 'game_play', requirements: ['slug' => self::SLUG], methods: ['GET'], priority: -10)]
    public function playPage(string $slug, #[CurrentUser] ?User $user, Request $request, RatingRepository $ratings, Voters $voters): Response
    {
        $project = $this->visible($slug, $user);
        if (!$project->isPublished()) {
            return $this->redirectToRoute('game', ['slug' => $slug]);
        }
        $this->projects->addPlay($project);
        return $this->render('game/play.html.twig', ['project' => $project, 'frame' => $this->play($project) . '/play/',
            'my_rating' => $ratings->scoreOf($project, $voters->of($request, $user))]);
    }

    #[Route('/{slug}/source/{path}', name: 'game_source', requirements: ['slug' => self::SLUG, 'path' => '.*'], defaults: ['path' => ''], methods: ['GET'], priority: -10)]
    public function source(string $slug, string $path, #[CurrentUser] ?User $user): Response
    {
        $project = $this->visible($slug, $user);
        $tree = $this->files->tree($project);
        $path = GameFiles::clean($path) ?? '';
        $file = $path !== '' && isset($tree[$path]) ? $this->files->path($project, $path) : null;
        if ($path !== '' && !$file) {
            // a folder: what is in it
            $prefix = $path . '/';
            if (!array_filter(array_keys($tree), fn ($p) => str_starts_with($p, $prefix))) {
                throw new NotFoundHttpException();
            }
        }
        $content = $file && GameFiles::isText($path) && filesize($file) <= GameFiles::MAX_EDIT ? (string)file_get_contents($file) : null;
        return $this->render('game/source.html.twig', [
            'project' => $project,
            'path' => $path,
            'entries' => $file ? [] : self::folder($tree, $path),
            'file' => $file !== null,
            'size' => $file ? filesize($file) : 0,
            'content' => $content,
            'image' => $file && preg_match(GameFiles::IMAGE, $path),
            'raw' => $this->play($project) . '/raw/',
            'crumbs' => $path === '' ? [] : explode('/', $path),
        ]);
    }

    #[Route('/{slug}/edit', name: 'game_edit', requirements: ['slug' => self::SLUG], methods: ['GET'], priority: -10)]
    #[IsGranted('ROLE_USER')]
    public function edit(string $slug, #[CurrentUser] User $user, DraftRepository $drafts, GitHub $github, TokenCipher $cipher, Slugs $slugs): Response
    {
        $project = $this->visible($slug, $user);
        if (!$project->isPublished()) {
            $this->addFlash('error', 'Le jeu n\'est pas encore publié : rien à éditer.');
            return $this->redirectToRoute('game', ['slug' => $slug]);
        }
        $canPush = false;
        try {
            $canPush = $user->getGithubToken() && $github->canPush($project->getRepoFullName(), $cipher->decrypt($user->getGithubToken()));
        } catch (\Throwable) {
        }
        return $this->render('game/edit.html.twig', [
            'project' => $project,
            'can_push' => $canPush,
            'drafts' => count($drafts->of($user, $project)),
            'fork_slug' => $canPush ? null : $slugs->available(Slugs::fromTitle($project->getSlug() . '-' . $user->getLogin())),
        ]);
    }

    #[Route('/{slug}/fork', name: 'game_fork', requirements: ['slug' => self::SLUG], methods: ['POST'], priority: -10)]
    #[IsGranted('ROLE_USER')]
    public function fork(string $slug, Request $request, #[CurrentUser] User $user, Projects $service): Response
    {
        $project = $this->visible($slug, $user);
        if (!$this->isCsrfTokenValid('fork-' . $project->getId(), (string)$request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        try {
            $fork = $service->fork($user, $project, trim((string)$request->request->get('slug')));
        } catch (ProjectException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('game', ['slug' => $slug]);
        }
        $this->addFlash('success', 'Fork en cours sur GitHub : votre copie sera prête dans quelques secondes.');
        return $this->redirectToRoute('studio_project', ['slug' => $fork->getSlug()]);
    }

    /** the pictures of a game's site, also on the portal's origin (og:image): raster images only */
    #[Route('/{slug}/{path}', name: 'game_site_image', requirements: ['slug' => self::SLUG, 'path' => '.+\.(png|jpe?g|gif|webp|ico)'], methods: ['GET'], priority: -20)]
    public function siteImage(string $slug, string $path): Response
    {
        $project = $this->projects->findOneBy(['slug' => $slug]);
        $file = $project && $project->hasSite() ? $this->files->path($project, 'site/' . $path) : null;
        if (!$file || !@getimagesize($file)) {
            throw new NotFoundHttpException();
        }
        $response = new BinaryFileResponse($file);
        $response->headers->set('Content-Type', GameFiles::mime($path));
        $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");
        $response->headers->set('Cache-Control', 'public, max-age=3600');
        return $response;
    }

    private function visible(string $slug, ?User $user): Project
    {
        $project = $this->projects->findOneBy(['slug' => $slug]);
        // not published yet: only its owner sees it
        if (!$project || (!$project->isPublished() && !$project->isOwnedBy($user))) {
            throw new NotFoundHttpException();
        }
        return $project;
    }

    private function play(Project $project): string
    {
        return rtrim($this->playUrl, '/') . '/' . $project->getSlug();
    }

    /** @return array<array{name: string, path: string, dir: bool, size: int}> */
    private static function folder(array $tree, string $path): array
    {
        $prefix = $path === '' ? '' : $path . '/';
        $entries = [];
        foreach ($tree as $file => $size) {
            if (!str_starts_with($file, $prefix)) {
                continue;
            }
            $rest = substr($file, strlen($prefix));
            $name = explode('/', $rest)[0];
            $dir = str_contains($rest, '/');
            $key = ($dir ? '0' : '1') . strtolower($name);
            $entries[$key] ??= ['name' => $name, 'path' => $prefix . $name, 'dir' => $dir, 'size' => 0];
            $entries[$key]['size'] += $size;
        }
        ksort($entries);
        return array_values($entries);
    }
}
