<?php

namespace App\Security;

use App\Entity\User;
use App\GitHub\GitHub;
use App\GitHub\GitHubException;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * "Sign in with GitHub" (the only way in): /connect/github sends the visitor to GitHub, GitHub sends them back
 * to /connect/github/check with a code, exchanged here for a token (kept encrypted: the playground uses it to
 * make the repositories, webhooks, forks and commits of its owner).
 */
final class GitHubAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    use TargetPathTrait;

    public const STATE_KEY = 'github_oauth_state';

    public function __construct(
        private GitHub $github,
        private UserRepository $users,
        private EntityManagerInterface $em,
        private TokenCipher $cipher,
        private UrlGeneratorInterface $urls,
        private string $appUrl,
    ) {
    }

    public function redirectUri(): string
    {
        return rtrim($this->appUrl, '/') . '/connect/github/check';
    }

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'connect_github_check';
    }

    public function authenticate(Request $request): Passport
    {
        $session = $request->getSession();
        $expected = $session->get(self::STATE_KEY);
        $session->remove(self::STATE_KEY);
        if (!$expected || !hash_equals($expected, (string)$request->query->get('state'))) {
            throw new CustomUserMessageAuthenticationException('La connexion GitHub a expiré, réessayez.');
        }
        if ($request->query->has('error')) {
            throw new CustomUserMessageAuthenticationException('Connexion GitHub annulée.');
        }
        try {
            $grant = $this->github->exchangeCode((string)$request->query->get('code'), $this->redirectUri());
            $profile = $this->github->user($grant['token']);
        } catch (GitHubException $e) {
            throw new CustomUserMessageAuthenticationException($e->getMessage());
        }

        $user = $this->users->findOneBy(['githubId' => (int)$profile['id']]) ?? new User((int)$profile['id'], $profile['login']);
        $user->setLogin($profile['login']);
        $user->setName($profile['name'] ?? null);
        $user->setAvatarUrl($profile['avatar_url'] ?? null);
        $user->setGithubToken($this->cipher->encrypt($grant['token']), $grant['scopes']);
        $user->touchLogin();
        $this->em->persist($user);
        $this->em->flush();

        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), fn () => $user));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $target = $this->getTargetPath($request->getSession(), $firewallName);
        $this->removeTargetPath($request->getSession(), $firewallName);
        return new RedirectResponse($target ?: $this->urls->generate('studio'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $session = $request->getSession();
        if ($session instanceof \Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('error', strtr($exception->getMessageKey(), $exception->getMessageData()));
        }
        return new RedirectResponse($this->urls->generate('home'));
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new RedirectResponse($this->urls->generate('connect_github'));
    }
}
