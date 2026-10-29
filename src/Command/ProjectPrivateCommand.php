<?php

namespace App\Command;

use App\Message\ShootProject;
use App\Repository\ProjectRepository;
use App\Service\PrivateFiles;
use App\Service\ProjectException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand('app:project:private', 'Gives a game private files from a folder (PrivateFiles): <slug> <folder> --as=assets/data')]
final class ProjectPrivateCommand extends Command
{
    public function __construct(private ProjectRepository $projects, private PrivateFiles $private, private MessageBusInterface $bus)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('slug', InputArgument::REQUIRED)
            ->addArgument('folder', InputArgument::REQUIRED, 'a folder of the server; its files keep their relative paths')
            ->addOption('as', null, InputOption::VALUE_REQUIRED, 'the folder in the game', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $project = $this->projects->findOneBy(['slug' => $input->getArgument('slug')]);
        $folder = rtrim((string)$input->getArgument('folder'), '/');
        if (!$project || !is_dir($folder)) {
            $output->writeln('<error>unknown game or folder</error>');
            return Command::FAILURE;
        }
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->isFile() && !$file->isLink()) {
                $path = trim($input->getOption('as') . '/' . substr($file->getPathname(), strlen($folder) + 1), '/');
                try {
                    $output->writeln('added: ' . $this->private->add($project, $path, $file->getPathname()));
                } catch (ProjectException $e) {
                    $output->writeln('<error>' . $e->getMessage() . '</error>');
                    return Command::FAILURE;
                }
            }
        }
        if ($project->isPublished() && (!$project->hasThumbnail() || $project->isShotThumbnail())) {
            $this->bus->dispatch(new ShootProject($project->getId(), true));
        }
        return Command::SUCCESS;
    }
}
