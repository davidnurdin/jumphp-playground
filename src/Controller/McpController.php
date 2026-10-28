<?php

namespace App\Controller;

use App\Entity\User;
use App\Mcp\McpServer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The MCP endpoint (Streamable HTTP): POST one JSON-RPC message, a JSON answer (no event stream).
 *   claude mcp add --transport http jumphp <APP_URL>/mcp --header "Authorization: Bearer jph_..."
 */
final class McpController extends AbstractController
{
    public function __construct(private McpServer $server, private string $appUrl)
    {
    }

    #[Route('/mcp', name: 'mcp', methods: ['POST'])]
    public function post(Request $request, #[CurrentUser] User $user): Response
    {
        // DNS rebinding: a browser page of another site may not call it
        $origin = $request->headers->get('Origin');
        if ($origin !== null && rtrim($origin, '/') !== rtrim($this->appUrl, '/')) {
            return new JsonResponse(McpServer::error(null, -32600, 'Origin not allowed'), 403);
        }
        try {
            $message = json_decode($request->getContent(), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(McpServer::error(null, -32700, 'Parse error'), 400);
        }
        if (is_array($message) && array_is_list($message)) {
            $answers = array_values(array_filter(array_map(fn ($m) => $this->server->handle($m, $user), $message)));
            return $answers ? $this->answer($answers) : new Response('', 202);
        }
        $answer = $this->server->handle($message, $user);
        return $answer === null ? new Response('', 202) : $this->answer($answer);
    }

    #[Route('/mcp', name: 'mcp_other', methods: ['GET', 'DELETE'])]
    public function other(): Response
    {
        return new Response('', 405, ['Allow' => 'POST']);
    }

    private function answer(array $data): JsonResponse
    {
        return (new JsonResponse())->setEncodingOptions(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)->setData($data);
    }
}
