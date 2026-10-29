<?php

namespace App\Command;

use App\Entity\Project;
use App\Entity\User;
use App\GitHub\GitHub;
use App\Repository\CategoryRepository;
use App\Repository\UserRepository;
use App\Service\Publisher;
use App\Service\Slugs;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Adds a public JumPHP game of GitHub to the directory (its owner gets it when they sign in). */
#[AsCommand('app:project:import', 'Publishes a public GitHub repository\'s game: owner/repo slug category [--game-dir=games/x]')]
final class ProjectImportCommand extends Command
{
    public function __construct(
        private GitHub $github,
        private UserRepository $users,
        private CategoryRepository $categories,
        private Slugs $slugs,
        private Publisher $publisher,
        private EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('repository', InputArgument::REQUIRED, 'owner/name')
            ->addArgument('slug', InputArgument::REQUIRED)
            ->addArgument('category', InputArgument::REQUIRED, 'category slug')
            ->addOption('game-dir', null, InputOption::VALUE_REQUIRED, 'the game folder (default: games/<slug>, the only game in games/, or the repository itself)')
            ->addOption('title', null, InputOption::VALUE_REQUIRED)
            ->addOption('description', null, InputOption::VALUE_REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $slug = $input->getArgument('slug');
        if ($problem = $this->slugs->problem($slug)) {
            $output->writeln("<error>{$problem->getMessage()}</error>");
            return Command::FAILURE;
        }
        $category = $this->categories->findOneBy(['slug' => $input->getArgument('category')]);
        $repo = $this->github->repo($input->getArgument('repository'));
        if (!$category || !$repo || $repo['private']) {
            $output->writeln('<error>unknown category, or no such public repository</error>');
            return Command::FAILURE;
        }
        $owner = $this->users->findOneBy(['githubId' => (int)$repo['owner']['id']]) ?? new User((int)$repo['owner']['id'], $repo['owner']['login']);
        $owner->setAvatarUrl($repo['owner']['avatar_url'] ?? null);
        $this->em->persist($owner);
        $project = new Project($owner, $slug, $input->getOption('title') ?? $repo['name'], $category, $repo['full_name'],
            $input->getOption('game-dir') ?? "games/$slug");
        $project->setDescription($input->getOption('description') ?? (string)($repo['description'] ?? ''));
        $project->setDefaultBranch($repo['default_branch'] ?? 'main');
        $this->em->persist($project);
        $this->em->flush();
        $result = $this->publisher->sync($project, true);
        $output->writeln(json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $result->status === 'failed' ? Command::FAILURE : Command::SUCCESS;
    }
}
