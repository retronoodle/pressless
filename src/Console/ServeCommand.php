<?php

declare(strict_types=1);

namespace Pressless\Console;

use Pressless\Bootstrap\Application;
use Pressless\Exception\SafeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Starts PHP's built-in development server with the project's router script.
 *
 * Pre-flight actions (`--fresh`, `--seed`) run before the server process is
 * spawned so a partially configured server never serves traffic.
 */
#[AsCommand(name: 'serve', description: 'Run the local development server.')]
final class ServeCommand extends Command
{
    public function __construct(private readonly Application $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Bind host', null)
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Bind port', null)
            ->addOption('fresh', null, InputOption::VALUE_NONE, 'Reset the application schema before serving')
            ->addOption('seed', null, InputOption::VALUE_NONE, 'Run the idempotent sample seeder before serving')
            ->addOption('allow-production-seed', null, InputOption::VALUE_NONE, 'Explicitly permit --seed in production');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->app->config();
        $host = (string) ($input->getOption('host') ?? $config->getString('serve.host', '127.0.0.1'));
        $port = (int) ($input->getOption('port') ?? $config->getInt('serve.port', 8000));

        try {
            $preflight = new ServePreflight($this->app->database(), $config, $this->app->logger());
            $result = $preflight->run(
                fresh: (bool) $input->getOption('fresh'),
                seed: (bool) $input->getOption('seed'),
                server: ['host' => $host, 'port' => $port],
                allowProductionSeed: (bool) $input->getOption('allow-production-seed'),
            );
        } catch (SafeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        foreach ($result['migrations']['applied'] as $version) {
            $output->writeln("<info>Applied {$version}</info>");
        }
        foreach ($result['migrations']['skipped'] as $version) {
            $output->writeln("<comment>Skipped {$version} (already applied)</comment>");
        }
        if ($result['migrations']['applied'] === []) {
            $output->writeln('<info>Database is up to date.</info>');
        }

        if ($result['seed'] !== null) {
            $this->reportSeedOutput($output, $result['seed']);
        }

        $publicDir = $config->projectRoot() . '/public';
        $router = $publicDir . '/router.php';

        if (!is_file($router)) {
            $output->writeln('<error>Router script not found at ' . $router . '</error>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>Pressless serving on http://%s:%d</info>', $host, $port));

        $cmd = [
            escapeshellarg(PHP_BINARY),
            '-S',
            escapeshellarg($host . ':' . $port),
            '-t',
            escapeshellarg($publicDir),
            escapeshellarg($router),
        ];

        $process = proc_open(implode(' ', $cmd), [
            1 => ['file', 'php://stdout', 'w'],
            2 => ['file', 'php://stderr', 'w'],
        ], $pipes);

        if (!is_resource($process)) {
            $output->writeln('<error>Failed to start the development server process.</error>');
            return Command::FAILURE;
        }

        $status = proc_close($process);
        return is_int($status) && $status === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @param array{admin_email: ?string, admin_password: ?string, collections_created: int} $report
     */
    private function reportSeedOutput(OutputInterface $output, array $report): void
    {
        if (($report['admin_email'] ?? null) !== null) {
            $output->writeln(sprintf(
                '<info>Seeded administrator: %s</info>',
                (string) $report['admin_email'],
            ));
            if (!empty($report['admin_password'])) {
                $output->writeln(sprintf(
                    '<comment>  temporary password: %s</comment>',
                    (string) $report['admin_password'],
                ));
            }
        } else {
            $output->writeln('<info>Existing administrator preserved.</info>');
        }

        $count = (int) ($report['collections_created'] ?? 0);
        if ($count > 0) {
            $output->writeln(sprintf('<info>Created %d sample collection(s).</info>', $count));
        } else {
            $output->writeln('<info>Sample collections already present.</info>');
        }
    }
}