<?php

namespace App\MessageHandler;

use App\Message\InitRepository;
use App\Message\SyncProject;
use App\Repository\ProjectRepository;
use App\Service\Publisher;
use App\Service\RepoInitializer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** The worker's jobs (bin/console messenger:consume async). */
final class ProjectJobs
{
    public function __construct(
        private ProjectRepository $projects,
        private RepoInitializer $initializer,
        private Publisher $publisher,
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
}
