<?php

namespace App\Controller;

use App\Entity\Project;
use App\Entity\User;
use App\Repository\ProjectRepository;
use App\Service\DraftAuthor;
use App\Service\ProjectException;
use App\Service\Publisher;
use App\Service\Sandbox;
use App\Service\VersionDrafts;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Util\TargetPathTrait;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A game's versions: each commit that changed it (Publisher::history()). Players get the newest; here an older one
 * is played again, or edited (in the sandbox, or one's online editor: from there pushed back or forked).
 */
#[Route(condition: "request.getHost() != env('PLAY_HOST')")]
final class VersionController extends AbstractController
{
    use TargetPathTrait;

    private const SLUG = '[a-z][a-z0-9-]{1,38}[a-z0-9]';

    public function __construct(private ProjectRepository $projects, private Publisher $publisher, private TranslatorInterface $translator, private string $playUrl)
    {
    }

    #[Route('/{slug}/versions', name: 'game_versions', requirements: ['slug' => self::SLUG], methods: ['GET'], priority: -5)]
    public function list(string $slug, Request $request): Response
    {
        $project = $this->published($slug);
        $response = $this->render('game/versions.html.twig', [
            'project' => $project,
            'history' => $this->publisher->history($project, $request->query->getInt('page', 1)),
        ]);
        $response->headers->set('X-Robots-Tag', 'noindex');
        return $response;
    }

    #[Route('/{slug}/versions/{sha}', name: 'game_version', requirements: ['slug' => self::SLUG, 'sha' => '[0-9a-f]{40}'], methods: ['GET'], priority: -5)]
    public function play(string $slug, string $sha): Response
    {
        $project = $this->published($slug);
        if ($sha === $project->getPublishedSha()) {
            return $this->redirectToRoute('game_play', ['slug' => $slug]);
        }
        try {
            $this->publisher->version($project, $sha);
        } catch (ProjectException $e) {
            $this->addFlash('error', $e->trans($this->translator));
            return $this->redirectToRoute('game_versions', ['slug' => $slug]);
        }
        $response = $this->render('game/version.html.twig', [
            'project' => $project,
            'sha' => $sha,
            'frame' => rtrim($this->playUrl, '/') . '/' . $project->getSlug() . '/play/?version=' . $sha,
        ]);
        $response->headers->set('X-Robots-Tag', 'noindex');
        return $response;
    }

    /** That version as one's drafts: the sandbox (no account) or one's online editor (then pushed back, or forked). */
    #[Route('/{slug}/versions/{sha}/{to}', name: 'game_version_edit', requirements: ['slug' => self::SLUG, 'sha' => '[0-9a-f]{40}', 'to' => 'bac-a-sable|editeur'], methods: ['POST'], priority: -5)]
    public function edit(string $slug, string $sha, string $to, Request $request, #[CurrentUser] ?User $user, Sandbox $sandbox, VersionDrafts $drafts): Response
    {
        $project = $this->published($slug);
        if (!$this->isCsrfTokenValid('version-' . $sha, (string)$request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if ($to === 'editeur' && !$user) {
            $this->saveTargetPath($request->getSession(), 'main', $this->generateUrl('game_version', ['slug' => $slug, 'sha' => $sha]));
            return $this->redirectToRoute('connect_github');
        }
        $response = $this->redirectToRoute($to === 'editeur' ? 'game_edit' : 'sandbox_game', ['slug' => $slug]);
        try {
            $this->publisher->version($project, $sha);
            $who = $user && $to === 'editeur' ? DraftAuthor::user($user) : DraftAuthor::visitor($sandbox->makeVisitor($request, $response));
            $made = $drafts->make($project, $sha, $who);
        } catch (ProjectException $e) {
            $this->addFlash('error', $e->trans($this->translator));
            return $this->redirectToRoute('game_versions', ['slug' => $slug]);
        }
        $this->addFlash('success', $this->translator->trans('Version %sha% ouverte : %files% fichier(s) diffèrent de la version en ligne.', ['%sha%' => substr($sha, 0, 7), '%files%' => $made['files']])
            . ($made['skipped'] ? ' ' . $this->translator->trans('%count% fichier(s) non texte (images, sons…) restent ceux d\'aujourd\'hui.', ['%count%' => $made['skipped']]) : ''));
        return $response;
    }

    private function published(string $slug): Project
    {
        $project = $this->projects->findOneBy(['slug' => $slug]);
        if (!$project || !$project->isPublished()) {
            throw new NotFoundHttpException();
        }
        return $project;
    }
}
