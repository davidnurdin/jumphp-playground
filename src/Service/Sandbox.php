<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The sandbox: anyone, without an account, changes a game (hello-world first) in the online editor and plays
 * it. A visitor is the random id of their jumphp_sandbox cookie; their drafts (DraftAuthor) are forgotten after
 * PURGE_DAYS without a change. The editor's calls carry token() (the cookie is HttpOnly, the page has the token).
 */
final class Sandbox
{
    public const COOKIE = 'jumphp_sandbox';
    public const START = 'hello-world';
    public const MAX_FILES = 40;
    public const MAX_FILE = 128 * 1024;
    public const PURGE_DAYS = 30;

    public function __construct(#[\SensitiveParameter] private string $kernelSecret)
    {
    }

    public function visitor(Request $request): ?string
    {
        $id = (string)$request->cookies->get(self::COOKIE);
        return preg_match('/^[0-9a-f]{32}$/', $id) ? $id : null;
    }

    /** The visitor, made for this browser when it has none yet (the cookie goes with $response). */
    public function makeVisitor(Request $request, Response $response): string
    {
        $id = $this->visitor($request) ?? bin2hex(random_bytes(16));
        // renewed at each visit: the sandbox lives as long as it is used
        $response->headers->setCookie(Cookie::create(self::COOKIE, $id, new \DateTimeImmutable('+' . self::PURGE_DAYS . ' days'), '/', null, null, true, false, Cookie::SAMESITE_LAX));
        return $id;
    }

    public function token(string $visitor): string
    {
        return hash_hmac('sha256', 'sandbox|' . $visitor, $this->kernelSecret);
    }
}
