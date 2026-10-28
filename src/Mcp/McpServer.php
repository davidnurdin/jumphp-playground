<?php

namespace App\Mcp;

use App\Entity\User;

/**
 * The MCP server of the playground (Model Context Protocol, Streamable HTTP transport, plain JSON answers):
 * JSON-RPC 2.0 messages from the creator's Claude, signed in with their personal token (/studio/mcp).
 * The tools themselves are in McpTools.
 */
final class McpServer
{
    public const VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    public function __construct(private McpTools $tools)
    {
    }

    /** One JSON-RPC message; null for a notification (no answer). */
    public function handle(mixed $message, User $user): ?array
    {
        if (!is_array($message) || ($message['jsonrpc'] ?? null) !== '2.0' || !is_string($message['method'] ?? null)) {
            return self::error($message['id'] ?? null, -32600, 'Invalid Request');
        }
        $id = $message['id'] ?? null;
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];
        if (!array_key_exists('id', $message)) {
            return null;    // notifications/initialized, notifications/cancelled...
        }
        return match ($message['method']) {
            'initialize' => self::result($id, [
                'protocolVersion' => in_array($params['protocolVersion'] ?? '', self::VERSIONS, true) ? $params['protocolVersion'] : self::VERSIONS[0],
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'jumphp-playground', 'title' => 'JumPHP playground', 'version' => '1.0.0'],
                'instructions' => $this->tools->instructions(),
            ]),
            'ping' => self::result($id, new \stdClass()),
            'tools/list' => self::result($id, ['tools' => $this->tools->definitions()]),
            'tools/call' => $this->call($id, $params, $user),
            'resources/list' => self::result($id, ['resources' => []]),
            'prompts/list' => self::result($id, ['prompts' => []]),
            default => self::error($id, -32601, 'Method not found: ' . $message['method']),
        };
    }

    private function call(mixed $id, array $params, User $user): array
    {
        $name = (string)($params['name'] ?? '');
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        if (!$this->tools->has($name)) {
            return self::error($id, -32602, "Unknown tool: $name");
        }
        try {
            $data = $this->tools->call($name, $arguments, $user);
            return self::result($id, [
                'content' => [['type' => 'text', 'text' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
                'structuredContent' => $data,
                'isError' => false,
            ]);
        } catch (McpToolError $e) {
            return self::result($id, ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true]);
        }
    }

    private static function result(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    public static function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
