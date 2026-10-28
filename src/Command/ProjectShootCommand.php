<?php

namespace App\Command;

use App\Message\ShootProject;
use App\Repository\ProjectRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand('app:project:shoot', 'Queues a screenshot of games without a picture (the "shots" worker makes them one at a time)')]
final class ProjectShootCommand extends Command
{
    public function __construct(private ProjectRepository $projects, private MessageBusInterface $bus)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('slugs', InputArgument::IS_ARRAY, 'the games (default: every published game without a picture)')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'also the games that have a picture (with the slugs given)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $slugs = $input->getArgument('slugs');
        $projects = $slugs ? $this->projects->findBy(['slug' => $slugs]) : $this->projects->findBy(['hasThumbnail' => false]);
        foreach ($projects as $project) {
            if (!$project->isPublished() || ($project->hasThumbnail() && !$input->getOption('force'))) {
                continue;
            }
            $this->bus->dispatch(new ShootProject($project->getId(), (bool)$input->getOption('force')));
            $output->writeln("queued: {$project->getSlug()}");
        }
        return Command::SUCCESS;
    }
}
