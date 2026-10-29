<?php

namespace App\Controller;

use App\Repository\DraftRepository;
use App\Repository\ProjectRepository;
use App\Service\DraftAuthor;
use App\Service\Sandbox;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** The sandbox: try JumPHP without an account, from hello-world or any game (the online editor, no push). */
#[Route(condition: "request.getHost() != env('PLAY_HOST')")]
final class SandboxController extends AbstractController
{
    #[Route('/bac-a-sable', name: 'sandbox', methods: ['GET'])]
    #[Route('/sandbox', name: 'sandbox_en', methods: ['GET'])]
    public function start(): Response
    {
        return $this->redirectToRoute('sandbox_game', ['slug' => Sandbox::START]);
    }

    #[Route('/bac-a-sable/{slug}', name: 'sandbox_game', requirements: ['slug' => '[a-z][a-z0-9-]{1,38}[a-z0-9]'], methods: ['GET'])]
    public function game(string $slug, Request $request, ProjectRepository $projects, DraftRepository $drafts, Sandbox $sandbox): Response
    {
        $project = $projects->findOneBy(['slug' => $slug]);
        if (!$project || !$project->isPublished()) {
            throw new NotFoundHttpException();
        }
        $drafts->purgeVisitors(new \DateTimeImmutable('-' . Sandbox::PURGE_DAYS . ' days'));
        $response = new Response();
        $visitor = $sandbox->makeVisitor($request, $response);
        $response->headers->set('X-Robots-Tag', 'noindex');
        return $this->render('game/edit.html.twig', [
            'project' => $project,
            'sandbox' => true,
            'api' => $this->generateUrl('sandbox_tree', ['slug' => $slug]),
            'token' => $sandbox->token($visitor),
            'can_push' => false,
            'drafts' => count($drafts->of(DraftAuthor::visitor($visitor), $project)),
            'fork_slug' => null,
        ], $response);
    }
}
