<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;

/** Requests to the games origin (PLAY_HOST): no firewall, no session, no cookie there. */
final class PlayHostMatcher implements RequestMatcherInterface
{
    public function __construct(private string $playHost)
    {
    }

    public function matches(Request $request): bool
    {
        return strcasecmp($request->getHost(), $this->playHost) === 0;
    }
}
