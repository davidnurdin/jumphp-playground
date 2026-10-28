<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\ProjectRepository;
use App\Repository\RatingRepository;
use App\Service\Voters;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** A player's mark for a game, 1 to 5 stars, without an account (Voters). Only from the portal's own pages. */
#[Route(condition: "request.getHost() != env('PLAY_HOST')")]
final class RatingController extends AbstractController
{
    #[Route('/{slug}/note', name: 'game_rate', requirements: ['slug' => '[a-z][a-z0-9-]{1,38}[a-z0-9]'], methods: ['POST'], priority: -10)]
    public function rate(string $slug, Request $request, #[CurrentUser] ?User $user, ProjectRepository $projects, RatingRepository $ratings, Voters $voters, string $appUrl): JsonResponse
    {
        // a page of the portal, not another site (no CSRF token: the players have no session)
        $origin = (string)$request->headers->get('Origin');
        if ($origin !== rtrim($appUrl, '/') && $request->headers->get('Sec-Fetch-Site') !== 'same-origin') {
            throw new AccessDeniedHttpException('Origin');
        }
        $project = $projects->findOneBy(['slug' => $slug]);
        if (!$project || !$project->isPublished()) {
            throw new NotFoundHttpException();
        }
        $score = $request->toArray()['score'] ?? null;
        if (!is_int($score) || $score < 1 || $score > 5) {
            throw new BadRequestHttpException('score: 1 to 5');
        }
        $response = new JsonResponse();
        $ratings->rate($project, $voters->make($request, $user, $response), $score);
        return $response->setData(['rating' => $project->getRating(), 'count' => $project->getRatingCount(), 'mine' => $score]);
    }
}
