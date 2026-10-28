<?php

namespace App\Message;

/** Photograph a published game without a picture (headless Chrome), for the directory. */
final class ShootProject
{
    public function __construct(public readonly int $projectId, public readonly bool $force = false)
    {
    }
}
