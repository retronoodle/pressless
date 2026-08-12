<?php

declare(strict_types=1);

namespace Stead\Http\Controller;

use Stead\Backups\BackupRepository;
use Stead\Backups\BackupRunner;
use Stead\Backups\BackupStatus;
use Stead\Backups\BackupTrigger;
use Stead\Backups\Dump\DumperFactory;
use Stead\Backups\Storage\StorageTargetFactory;
use Stead\Auth\User;
use Stead\Config\Configuration;
use Stead\Database\Connection;
use Stead\Update\UpdateChecker;
use Stead\View\Renderer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders the manual-update instructions page reachable from the admin
 * dashboard's update banner.
 *
 * Before showing instructions, a pre-update backup is triggered. If
 * the backup fails (storage unreachable, dump error, etc.), the page
 * fails closed: instructions are not rendered; the admin sees the
 * failure instead. This mirrors the "fail closed" precedent already
 * set by login throttling and update-check errors.
 *
 * The page always shows the same instructions regardless of the actual
 * available version — the version-specific bits come from the banner on
 * the dashboard. Showing a one-click "apply update" button would violate
 * the explicit v1 scope (PRD §11: manual download + extract only).
 */
final class AdminUpdateController
{
    public function __construct(
        private readonly Renderer $renderer,
        private readonly UpdateChecker $updateChecker,
        private readonly Configuration $config,
        private readonly Connection $connection,
        private readonly BackupRunner $backupRunner,
        private readonly BackupRepository $backups,
    ) {
    }

    /**
     * @param array<string, string> $parameters
     */
    public function show(Request $request, array $parameters = []): Response
    {
        $user = $request->attributes->get('user');

        $result = null;
        try {
            $result = $this->updateChecker->check();
        } catch (\Throwable $e) {
            // Same second-line-of-defence pattern as AdminController.
            // Fall through with $result = null; the template renders an
            // empty-state "no update available" view in that case.
        }

        $available = $result !== null && $result->hasUpdate();
        $latest = $available ? (string) $result->latestVersion : '';
        $installed = $result !== null ? $result->installedVersion : '';
        $downloadUrl = $available ? (string) $result->downloadUrl : '';

        // Trigger a pre-update backup only when there's actually an
        // update to instruct about — there's no point creating a
        // backup the admin is about to ignore.
        $preUpdateBackup = null;
        $backupError = null;
        if ($available) {
            try {
                $preUpdateBackup = $this->backupRunner->run(BackupTrigger::PRE_UPDATE);
                if ($preUpdateBackup->status() !== BackupStatus::SUCCESS) {
                    $backupError = $preUpdateBackup->errorMessage() ?? 'Backup did not complete successfully.';
                    $preUpdateBackup = null;
                }
            } catch (\Throwable $e) {
                $backupError = $e->getMessage();
            }
        }

        return new Response(
            $this->renderer->render('admin/update', [
                'user_name' => $user instanceof User ? $user->name : '',
                'user_role' => $user instanceof User ? $user->roleName : '',
                'available' => $available,
                'latest_version' => $latest,
                'installed_version' => $installed,
                'download_url' => $downloadUrl,
                'pre_update_backup_id' => $preUpdateBackup?->id(),
                'pre_update_backup_error' => $backupError,
            ]),
            Response::HTTP_OK,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
    }

    public static function buildBackupRunner(
        Configuration $config,
        Connection $connection,
        \Psr\Log\LoggerInterface $logger,
        BackupRepository $backups,
    ): BackupRunner {
        return new BackupRunner(
            $config,
            $backups,
            new StorageTargetFactory($config),
            new DumperFactory($connection, $config),
            \Stead\Http\Routes::resolveMediaRoot($config),
            $logger,
        );
    }
}
