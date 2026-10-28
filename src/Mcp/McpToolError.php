<?php

namespace App\Mcp;

/** A tool call that did not work: told to Claude as a tool error (isError), not a protocol error. */
final class McpToolError extends \RuntimeException
{
}
