<?php

namespace App\Message;

/** Publish the last commit of a project (after a push). */
final class SyncProject
{
    public function __construct(public readonly int $projectId, public readonly bool $force = false)
    {
    }
}
