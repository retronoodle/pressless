<?php

declare(strict_types=1);

namespace Stead\Console;

use Stead\Bootstrap\Application;
use Stead\Exception\SafeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use ZipArchive;

/**
 * Builds a version-stamped, dependency-vendored, dev-file-stripped dist ZIP.
 *
 * The build is deterministic: every file that ends up in the archive comes
 * from the explicit {@see self::INCLUDE_PATHS} list (or the freshly installed
 * `vendor/` directory after `composer install --no-dev`). Anything outside
 * that list is excluded — there is no blanket copy that could accidentally
 * pull in `.env`, `tests/`, or build tooling.
 *
 * The resulting ZIP is meant to be extracted on top of an existing Stead
 * install by an end user. It contains:
 *
 *   - the production-only `vendor/` tree
 *   - `src/`, `public/`, `config/`, `templates/`, `themes/`, `bin/`, `database/`
 *   - a `VERSION` file stamped with the version being built
 *   - top-level docs the user might want (`README.md`)
 *
 * It deliberately does NOT contain: `tests/`, `.git/`, `openspec/`, `.env`,
 * `phpunit.xml`, `phpstan.neon`, the `docs/` working notes, or any
 * repository-internal tooling that has no purpose on a deployed site.
 */
#[AsCommand(name: 'release', description: 'Build a production-only Stead release ZIP.')]
final class ReleaseCommand extends Command
{
    /**
     * Explicit include list: every top-level path that is allowed in the
     * dist ZIP. Anything not listed here is excluded. Order is preserved
     * during the copy but is not significant to the produced archive.
     *
     * The `vendor/` entry is a sentinel — at copy time it is replaced by
     * the freshly produced `vendor/` directory from the composer install
     * step, which lives next to `composer.json` inside the build dir.
     *
     * @var list<string>
     */
    private const INCLUDE_PATHS = [
        'bin',
        'config',
        'database',
        'public',
        'src',
        'templates',
        'themes',
        'vendor',
        'README.md',
        'VERSION',
    ];

    /**
     * Paths that, if encountered as a child of an included directory, must
     * never be copied. The include-list above is the primary mechanism; this
     * list is a defence-in-depth guard for transient / per-machine content
     * that lives inside otherwise-included trees (e.g. `var/cache` is not
     * shipped because no production install needs the dev server's cache).
     *
     * @var list<string>
     */
    private const EXCLUDE_PATH_FRAGMENTS = [
        '/.git/',
        '/tests/',
        '/.phpunit.cache/',
        '/.phpunit.result.cache',
        '/.phpstan.cache/',
        '/openspec/',
    ];

    public function __construct(private readonly Application $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('version', InputArgument::REQUIRED, 'Version to stamp into the release (e.g. 1.2.3).')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output ZIP path (defaults to <project-root>/stead-<version>.zip).')
            ->addOption('keep-build-dir', null, InputOption::VALUE_NONE, 'Leave the temp build directory on disk after zipping (debug aid).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $version = (string) $input->getArgument('version');
        $this->assertVersionShape($version);

        $projectRoot = $this->app->config()->projectRoot();
        $zipPath = (string) ($input->getOption('output') ?? sprintf('%s/stead-%s.zip', $projectRoot, $version));
        $keepBuildDir = (bool) $input->getOption('keep-build-dir');

        $buildDir = $this->createTempBuildDir();
        if ($keepBuildDir) {
            $output->writeln(sprintf('<comment>Keeping build dir at %s</comment>', $buildDir));
        }

        try {
            $output->writeln('<info>Staging composer manifests...</info>');
            $this->stageComposerManifests($projectRoot, $buildDir);

            $output->writeln('<info>Installing production-only dependencies...</info>');
            $this->runComposerInstall($buildDir, $output);

            $output->writeln('<info>Writing VERSION file...</info>');
            $this->writeVersionFile($buildDir, $version);

            $output->writeln('<info>Cleaning staging artefacts...</info>');
            $this->removeStagingArtefacts($buildDir);

            $output->writeln('<info>Applying explicit include list...</info>');
            $this->applyIncludeList($projectRoot, $buildDir);

            $output->writeln(sprintf('<info>Zipping to %s...</info>', $zipPath));
            $this->zipBuildDir($buildDir, $zipPath);
        } finally {
            if (!$keepBuildDir) {
                $this->rmRecursive($buildDir);
            }
        }

        $output->writeln(sprintf('<info>Built %s</info>', $zipPath));
        return Command::SUCCESS;
    }

    /**
     * Rejects anything that isn't a sensible SemVer-ish release tag — the
     * VERSION file is going to be parsed at runtime by the update checker,
     * so keeping the format predictable now prevents a class of dumb bugs
     * (whitespace, embedded newlines, accidental `--help` output in a
     * version string) from ever shipping.
     */
    private function assertVersionShape(string $version): void
    {
        if ($version === '' || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.\-]+)?$/', $version)) {
            throw new SafeException(
                sprintf('Invalid version "%s". Expected SemVer (e.g. 1.2.3 or 1.2.3-rc.1).', $version),
                ['version' => $version],
            );
        }
    }

    private function createTempBuildDir(): string
    {
        $base = sys_get_temp_dir();
        $dir = tempnam($base, 'stead-release-');
        if ($dir === false) {
            throw new SafeException('Could not create a temporary build directory.');
        }
        // tempnam creates a file; we need a directory.
        @unlink($dir);
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new SafeException(sprintf('Could not create temp build directory at %s.', $dir));
        }
        return $dir;
    }

    private function stageComposerManifests(string $projectRoot, string $buildDir): void
    {
        foreach (['composer.json', 'composer.lock'] as $manifest) {
            $src = $projectRoot . '/' . $manifest;
            if (!is_file($src)) {
                throw new SafeException(sprintf('Required manifest %s is missing from the project root.', $manifest));
            }
            if (!@copy($src, $buildDir . '/' . $manifest)) {
                throw new SafeException(sprintf('Could not copy %s into the build directory.', $manifest));
            }
        }
    }

    private function runComposerInstall(string $buildDir, OutputInterface $output): void
    {
        $command = [
            'composer',
            'install',
            '--no-dev',
            '--no-interaction',
            '--no-progress',
            '--optimize-autoloader',
            '--no-scripts',
        ];

        $exit = $this->runProcess($command, $buildDir, $output);
        if ($exit !== 0) {
            throw new SafeException(sprintf('composer install failed (exit %d). See output above.', $exit));
        }
    }

    /**
     * @param list<string> $command
     */
    private function runProcess(array $command, string $cwd, OutputInterface $output): int
    {
        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($command, $descriptorSpec, $pipes, $cwd);
        if (!is_resource($proc)) {
            throw new SafeException(sprintf('Could not start process: %s', implode(' ', $command)));
        }
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        if ($stdout !== '') {
            $output->writeln($stdout);
        }
        if ($stderr !== '') {
            $output->writeln("<error>{$stderr}</error>");
        }
        return $exit;
    }

    private function writeVersionFile(string $buildDir, string $version): void
    {
        // The VERSION file is plain text, trimmed, no trailing newline
        // beyond the one we write here. Update checker parses it via
        // file_get_contents + trim(), so keep it boring on purpose.
        if (file_put_contents($buildDir . '/VERSION', $version . "\n") === false) {
            throw new SafeException('Could not write VERSION file into the build directory.');
        }
    }

    /**
     * Removes the composer manifests and other staging-time artefacts that
     * are not part of the explicit include list. Doing this before the
     * include list walks the build dir means the include list stays a
     * pure source-of-truth ("these are the only things in the dist") and
     * isn't fighting a build dir that already contains leftovers.
     */
    private function removeStagingArtefacts(string $buildDir): void
    {
        foreach (['composer.json', 'composer.lock'] as $stagingFile) {
            $path = $buildDir . '/' . $stagingFile;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function applyIncludeList(string $projectRoot, string $buildDir): void
    {
        foreach (self::INCLUDE_PATHS as $relative) {
            $src = $projectRoot . '/' . $relative;
            $dest = $buildDir . '/' . $relative;

            if ($relative === 'VERSION') {
                // VERSION is written earlier into the build dir directly;
                // skip the copy from the source (the source VERSION, if any,
                // represents a developer machine, not the build being made).
                continue;
            }
            if ($relative === 'vendor') {
                // vendor/ is already inside the build dir from the composer
                // install step; just sanity-check it exists.
                if (!is_dir($dest)) {
                    throw new SafeException('composer install did not produce a vendor/ directory.');
                }
                continue;
            }

            if (!file_exists($src)) {
                throw new SafeException(sprintf('Required path "%s" is missing from the project root.', $relative));
            }

            if (is_dir($src)) {
                $this->copyDir($src, $dest);
            } else {
                $this->copyFile($src, $dest);
            }
        }
    }

    private function copyFile(string $src, string $dest): void
    {
        $this->ensureDir(dirname($dest));
        if (!@copy($src, $dest)) {
            throw new SafeException(sprintf('Could not copy %s to %s.', $src, $dest));
        }
    }

    private function copyDir(string $src, string $dest): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        $this->ensureDir($dest);
        foreach ($iterator as $entry) {
            $relative = substr($entry->getPathname(), strlen($src) + 1);
            if ($this->isExcluded($relative)) {
                continue;
            }
            $target = $dest . '/' . $relative;
            if ($entry->isDir()) {
                $this->ensureDir($target);
            } elseif ($entry->isFile()) {
                $this->ensureDir(dirname($target));
                if (!@copy($entry->getPathname(), $target)) {
                    throw new SafeException(sprintf('Could not copy %s to %s.', $entry->getPathname(), $target));
                }
            }
        }
    }

    private function isExcluded(string $relativePath): bool
    {
        $needle = '/' . ltrim($relativePath, '/');
        foreach (self::EXCLUDE_PATH_FRAGMENTS as $fragment) {
            if (str_contains($needle, $fragment)) {
                return true;
            }
        }
        return false;
    }

    private function ensureDir(string $path): void
    {
        if (is_dir($path)) {
            return;
        }
        if (!@mkdir($path, 0775, true) && !is_dir($path)) {
            throw new SafeException(sprintf('Could not create directory %s.', $path));
        }
    }

    private function zipBuildDir(string $buildDir, string $zipPath): void
    {
        if (is_file($zipPath)) {
            if (!@unlink($zipPath)) {
                throw new SafeException(sprintf('Could not remove pre-existing ZIP at %s.', $zipPath));
            }
        }

        $zip = new ZipArchive();
        $result = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($result !== true) {
            throw new SafeException(sprintf('Could not open ZIP at %s (ZipArchive code %d).', $zipPath, (int) $result));
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($buildDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST,
            );
            foreach ($iterator as $entry) {
                $localName = substr($entry->getPathname(), strlen($buildDir) + 1);
                if ($entry->isDir()) {
                    $zip->addEmptyDir($localName);
                } elseif ($entry->isFile()) {
                    $zip->addFile($entry->getPathname(), $localName);
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function rmRecursive(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($path);
    }
}
