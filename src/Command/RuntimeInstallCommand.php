<?php

namespace App\Command;

use App\Service\Runtimes;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('app:runtime:install', 'Installs the shared JumPHP engines (JUMPHP_RUNTIMES, or the versions given) in public/engine/')]
final class RuntimeInstallCommand extends Command
{
    public function __construct(private Runtimes $runtimes)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('versions', InputArgument::IS_ARRAY, 'versions (default: JUMPHP_RUNTIMES)')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'a local runtime folder (JumPHP build/runtime) instead of the GitHub release')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'install again even if present');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $versions = $input->getArgument('versions') ?: $this->runtimes->wanted();
        $installed = $this->runtimes->installed();
        foreach ($versions as $version) {
            $version = ltrim($version, 'v');
            if (!$input->getOption('force') && in_array($version, $installed, true)) {
                $output->writeln("engine $version: already installed");
                continue;
            }
            $output->writeln("engine $version: " . $this->runtimes->install($version, $input->getOption('from')));
        }
        return Command::SUCCESS;
    }
}
