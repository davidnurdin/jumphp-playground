<?php

namespace App\MessageHandler;

use App\Message\InitRepository;
use App\Message\ShootProject;
use App\Message\SyncProject;
use App\Repository\ProjectRepository;
use App\Service\Publisher;
use App\Service\RepoInitializer;
use App\Service\Screenshots;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** The workers' jobs (bin/console messenger:consume async; the screenshots: messenger:consume shots). */
final class ProjectJobs
{
    public function __construct(
        private ProjectRepository $projects,
        private RepoInitializer $initializer,
        private Publisher $publisher,
        private Screenshots $screenshots,
    ) {
    }

    #[AsMessageHandler]
    public function init(InitRepository $message): void
    {
        if ($project = $this->projects->find($message->projectId)) {
            $this->initializer->init($project, $message->mode);
        }
    }

    #[AsMessageHandler]
    public function sync(SyncProject $message): void
    {
        if ($project = $this->projects->find($message->projectId)) {
            $this->publisher->sync($project, $message->force);
        }
    }

    #[AsMessageHandler]
    public function shoot(ShootProject $message): void
    {
        if ($project = $this->projects->find($message->projectId)) {
            $this->screenshots->shoot($project, $message->force);
        }
    }
}
