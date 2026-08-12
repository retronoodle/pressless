<?php

declare(strict_types=1);

namespace Stead\Update;

use Stead\Config\Configuration;
use Stead\Support\ProjectRoot;

/**
 * Reads the installed version from the `VERSION` file stamped at build time.
 *
 * The file is a single line of plain text with no leading/trailing whitespace
 * beyond the trailing newline we write in {@see \Stead\Console\ReleaseCommand}.
 * Reading is intentionally permissive: a missing or unparseable file is
 * reported as "unknown", never thrown — the update checker's fail-closed
 * behaviour depends on the installed version being treated as a soft fact,
 * not a hard dependency on the filesystem state of `VERSION`.
 */
final class InstalledVersion
{
    public function __construct(private readonly string $projectRoot)
    {
    }

    /**
     * Resolve the project root by walking the filesystem. Used in
     * production when the running PHP process is launched from inside the
     * Stead install (the common case). Tests should prefer
     * {@see self::fromConfig()} so they can pin the root to a temp dir.
     */
    public static function fromProjectRoot(): self
    {
        return new self(ProjectRoot::resolve());
    }

    /**
     * Build from the configured project root. The Configuration class
     * already knows where the install lives (see
     * {@see Configuration::projectRoot()}), so we don't need to walk the
     * filesystem at runtime — which makes the behaviour deterministic in
     * test contexts that don't put a `composer.json` + `src/` next to the
     * phpunit binary.
     */
    public static function fromConfig(Configuration $config): self
    {
        return new self($config->projectRoot());
    }

    public function projectRoot(): string
    {
        return $this->projectRoot;
    }

    /**
     * Returns the installed version, or null if it cannot be determined.
     */
    public function read(): ?string
    {
        $path = $this->projectRoot . '/VERSION';
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return null;
        }
        $trimmed = trim($contents);
        return $trimmed === '' ? null : $trimmed;
    }
}
