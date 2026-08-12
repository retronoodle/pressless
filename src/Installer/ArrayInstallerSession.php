<?php

declare(strict_types=1);

namespace Stead\Installer;

/**
 * An in-memory installer session for tests.
 *
 * Mirrors {@see NativeInstallerSession} semantics — including a fresh
 * identifier per store and a {@see destroy()} that clears state — without
 * touching PHP's session machinery or sending headers. Tests share a single
 * instance across requests so wizard state persists between steps.
 */
final class ArrayInstallerSession implements InstallerSessionStore
{
    /** @var array<string, mixed> */
    private array $wizard = [];

    public function start(): void
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function getWizard(): array
    {
        return $this->wizard;
    }

    /**
     * @param array<string, mixed> $wizard
     */
    public function setWizard(array $wizard): void
    {
        $this->wizard = $wizard;
    }

    public function clearWizard(): void
    {
        $this->wizard = [];
    }

    public function destroy(): void
    {
        $this->wizard = [];
    }
}