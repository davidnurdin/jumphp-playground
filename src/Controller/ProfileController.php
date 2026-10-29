<?php

namespace App\Controller;

use App\Repository\CommentRepository;
use App\Repository\ProjectRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** A developer's page (/dev/<GitHub login>): the games they made, the comments they wrote and those on their games. */
#[Route(condition: "request.getHost() != env('PLAY_HOST')")]
final class ProfileController extends AbstractController
{
    #[Route('/dev/{login}', name: 'profile', requirements: ['login' => '[A-Za-z0-9][A-Za-z0-9-]{0,38}'], methods: ['GET'])]
    public function show(string $login, UserRepository $users, ProjectRepository $projects, CommentRepository $comments): Response
    {
        $user = $users->findOneBy(['login' => $login]) ?? throw new NotFoundHttpException();
        if ($user->getLogin() !== $login) {
            return $this->redirectToRoute('profile', ['login' => $user->getLogin()], 301);
        }
        $games = $projects->publishedBy($user);
        $marks = array_filter($games, fn ($p) => $p->getRatingCount() > 0);
        return $this->render('profile/show.html.twig', [
            'dev' => $user,
            'games' => $games,
            'plays' => array_sum(array_map(fn ($p) => $p->getPlays(), $games)),
            'rating' => $marks ? round(array_sum(array_map(fn ($p) => $p->getRating(), $marks)) / count($marks), 1) : null,
            'written' => $comments->latestBy($user),
            'received' => $games ? $comments->latestOnGamesOf($user) : [],
        ]);
    }
}
