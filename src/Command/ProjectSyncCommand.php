<?php

namespace App\Command;

use App\Repository\ProjectRepository;
use App\Service\Publisher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('app:project:sync', 'Publishes the last commit of projects (all of them with --all)')]
final class ProjectSyncCommand extends Command
{
    public function __construct(private ProjectRepository $projects, private Publisher $publisher)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('slugs', InputArgument::IS_ARRAY)
            ->addOption('all', null, InputOption::VALUE_NONE)
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'publish again even when unchanged');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projects = $input->getOption('all') ? $this->projects->findAll()
            : array_filter(array_map(fn ($s) => $this->projects->findOneBy(['slug' => $s]), $input->getArgument('slugs')));
        $status = Command::SUCCESS;
        foreach ($projects as $project) {
            $result = $this->publisher->sync($project, (bool)$input->getOption('force'));
            $output->writeln($project->getSlug() . ': ' . json_encode($result->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            if ($result->status === 'failed') {
                $status = Command::FAILURE;
            }
        }
        return $status;
    }
}
