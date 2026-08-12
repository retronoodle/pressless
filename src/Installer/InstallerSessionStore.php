<?php

declare(strict_types=1);

namespace Stead\Installer;

/**
 * The session state the installer wizard needs.
 *
 * Implemented by {@see NativeInstallerSession} for real requests and by
 * {@see ArrayInstallerSession} in tests, so wizard step ordering can be
 * exercised without starting a PHP session or sending cookies.
 */
interface InstallerSessionStore
{
    public function start(): void;

    /**
     * @return array<string, mixed>
     */
    public function getWizard(): array;

    /**
     * @param array<string, mixed> $wizard
     */
    public function setWizard(array $wizard): void;

    public function clearWizard(): void;

    public function destroy(): void;
}