<?php

declare(strict_types=1);

namespace Pressless\Console;

use Pressless\Bootstrap\Application;
use Pressless\Database\Migrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'migrate', description: 'Apply pending database migrations.')]
final class MigrateCommand extends Command
{
    public function __construct(private readonly Application $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('fresh', null, InputOption::VALUE_NONE, 'Reset the application schema before migrating.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $migrator = new Migrator($this->app->database(), $this->app->config());

        if ($input->getOption('fresh')) {
            $output->writeln('<comment>Resetting application schema...</comment>');
            $migrator->reset();
        }

        $result = $migrator->migrate();
        foreach ($result['applied'] as $version) {
            $output->writeln("<info>Applied {$version}</info>");
        }
        foreach ($result['skipped'] as $version) {
            $output->writeln("<comment>Skipped {$version} (already applied)</comment>");
        }

        if ($result['applied'] === []) {
            $output->writeln('<info>Database is up to date.</info>');
        }
        return Command::SUCCESS;
    }
}
