<?php

namespace App\Security;

use App\Repository\UserRepository;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/** The MCP server: "Authorization: Bearer jph_..." (the token made in /studio/mcp). */
final class McpTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(private UserRepository $users)
    {
    }

    public function getUserBadgeFrom(string $accessToken): UserBadge
    {
        $user = str_starts_with($accessToken, 'jph_') ? $this->users->findByMcpToken($accessToken) : null;
        if (!$user) {
            throw new BadCredentialsException('Invalid token.');
        }
        return new UserBadge($user->getUserIdentifier(), fn () => $user);
    }
}
