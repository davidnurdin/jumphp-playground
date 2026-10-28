<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who marks a game: a signed-in creator ("u<id>"), else the browser, by a random id in the jumphp_voter cookie
 * (made at its first vote). Players need no account to give their mark.
 */
final class Voters
{
    public const COOKIE = 'jumphp_voter';

    public function of(Request $request, ?User $user): ?string
    {
        if ($user) {
            return 'u' . $user->getId();
        }
        $id = (string)$request->cookies->get(self::COOKIE);
        return preg_match('/^[0-9a-f]{32}$/', $id) ? "b$id" : null;
    }

    /** The voter, made for this browser when it has none yet (the cookie then goes with $response). */
    public function make(Request $request, ?User $user, Response $response): string
    {
        if ($voter = $this->of($request, $user)) {
            return $voter;
        }
        $id = bin2hex(random_bytes(16));
        $response->headers->setCookie(Cookie::create(self::COOKIE, $id, new \DateTimeImmutable('+2 years'), '/', null, null, true, false, Cookie::SAMESITE_LAX));
        return "b$id";
    }
}
