<?php

namespace App\Controller;

use App\GitHub\GitHub;
use App\Security\GitHubAuthenticator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Sign in with GitHub (the only way in); GitHubAuthenticator does the rest. */
final class AuthController extends AbstractController
{
    #[Route('/connect/github', name: 'connect_github', methods: ['GET'])]
    public function connect(Request $request, GitHub $github, GitHubAuthenticator $authenticator): Response
    {
        if (!$github->isConfigured()) {
            return $this->render('auth/not_configured.html.twig');
        }
        $state = bin2hex(random_bytes(16));
        $request->getSession()->set(GitHubAuthenticator::STATE_KEY, $state);
        return $this->redirect($github->authorizeUrl($state, $authenticator->redirectUri()));
    }

    #[Route('/connect/github/check', name: 'connect_github_check', methods: ['GET'])]
    public function check(): never
    {
        throw new \LogicException('GitHubAuthenticator handles this route.');
    }

    #[Route('/logout', name: 'logout', methods: ['GET', 'POST'])]
    public function logout(): never
    {
        throw new \LogicException('The firewall handles this route.');
    }
}
