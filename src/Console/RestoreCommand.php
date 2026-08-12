<?php

declare(strict_types=1);

namespace Stead\Console;

use Stead\Backups\BackupRepository;
use Stead\Backups\Restore\RestoreRunner;
use Stead\Backups\Storage\StorageTargetFactory;
use Stead\Bootstrap\Application;
use Stead\Config\Configuration;
use Stead\Exception\SafeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

#[AsCommand(name: 'backup:restore', description: 'Restore the database and media directory from a backup.')]
final class RestoreCommand extends Command
{
    public function __construct(private readonly Application $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Backup id to restore.');
        $this->addOption(
            'yes',
            null,
            InputOption::VALUE_NONE,
            'Skip the interactive confirmation step (use with care).',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->app->config();
        $connection = $this->app->database();

        $id = (int) $input->getArgument('id');
        if ($id <= 0) {
            $output->writeln('<error>Invalid backup id.</error>');
            return Command::FAILURE;
        }

        $repository = new BackupRepository($connection);
        $backup = $repository->find($id);
        if ($backup === null) {
            $output->writeln(sprintf('<error>No backup found with id %d.</error>', $id));
            return Command::FAILURE;
        }
        $backup->assertSucceeded();

        $output->writeln(sprintf(
            '<info>About to restore backup #%d:</info>',
            $backup->id(),
        ));
        $output->writeln(sprintf('  target: %s', $backup->target()));
        $output->writeln(sprintf('  created: %s', $backup->createdAt()));
        $output->writeln(sprintf('  size: %s', self::formatBytes($backup->sizeBytes())));
        $output->writeln(sprintf('  storage_key: %s', $backup->storageKey()));
        $output->writeln('<comment>This will overwrite the current database and media directory.</comment>');

        if (!$input->getOption('yes')) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                'Type "yes" to continue: ',
                false,
            );
            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('<comment>Restore aborted.</comment>');
                return Command::SUCCESS;
            }
        }

        $runner = new RestoreRunner(
            $config,
            $connection,
            $repository,
            new StorageTargetFactory($config),
            $this->resolveMediaRoot($config),
        );

        try {
            $runner->restore($backup);
        } catch (SafeException $e) {
            $output->writeln('<error>Restore failed: ' . $e->publicMessage() . '</error>');
            return Command::FAILURE;
        } catch (\Throwable $e) {
            $output->writeln('<error>Restore failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>Restore complete.</info>');
        return Command::SUCCESS;
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
