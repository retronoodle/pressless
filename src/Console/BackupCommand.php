<?php

declare(strict_types=1);

namespace Stead\Console;

use Stead\Backups\BackupRepository;
use Stead\Backups\BackupRunner;
use Stead\Backups\BackupTrigger;
use Stead\Backups\Dump\DumperFactory;
use Stead\Backups\Storage\StorageTargetFactory;
use Stead\Bootstrap\Application;
use Stead\Config\Configuration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'backup', description: 'Create a backup of the database and media directory.')]
final class BackupCommand extends Command
{
    public function __construct(private readonly Application $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'scheduled',
            null,
            InputOption::VALUE_NONE,
            'Run only if the configured backup frequency has elapsed since the last successful run.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->app->config();
        $runner = $this->buildRunner($config);

        if ($input->getOption('scheduled')) {
            if (!$runner->isScheduledDue()) {
                $output->writeln('<comment>Scheduled backup not due yet; skipping.</comment>');
                return Command::SUCCESS;
            }
            $trigger = BackupTrigger::SCHEDULED;
        } else {
            $trigger = BackupTrigger::MANUAL;
        }

        $output->writeln(sprintf('<info>Creating backup (trigger=%s)...</info>', $trigger));
        try {
            $backup = $runner->run($trigger);
        } catch (\Throwable $e) {
            $output->writeln('<error>Backup failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '<info>Backup #%d created (%s, %s).</info>',
            $backup->id(),
            $backup->target(),
            self::formatBytes((int) $backup->sizeBytes()),
        ));
        return Command::SUCCESS;
    }

    private function buildRunner(Configuration $config): BackupRunner
    {
        $connection = $this->app->database();
        return new BackupRunner(
            $config,
            new BackupRepository($connection),
            new StorageTargetFactory($config),
            new DumperFactory($connection, $config),
            $this->resolveMediaRoot($config),
            $this->app->logger(),
        );
    }

    private function resolveMediaRoot(Configuration $config): string
    {
        $relative = $config->getString('paths.storage', 'storage/media');
        if ($relative === '') {
            $relative = 'storage/media';
        }
        $absolute = $config->projectRoot() . '/' . ltrim($relative, '/');
        $real = realpath($absolute);
        return $real !== false ? $real : $absolute;
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return sprintf('%.1f MB', $bytes / (1024 * 1024));
        }
        if ($bytes >= 1024) {
            return sprintf('%.1f KB', $bytes / 1024);
        }
        return sprintf('%d B', $bytes);
    }
}
