<?php

namespace App\Message;

/** Make the repository of a new project (a JumPHP project with its game), or set up an imported / forked one. */
final class InitRepository
{
    public const NEW = 'new';
    public const FORK = 'fork';
    public const IMPORT = 'import';

    public function __construct(public readonly int $projectId, public readonly string $mode = self::NEW)
    {
    }
}
