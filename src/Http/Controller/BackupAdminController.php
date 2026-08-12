<?php

declare(strict_types=1);

namespace Stead\Http\Controller;

use Stead\Auth\User;
use Stead\Backups\Backup;
use Stead\Backups\BackupRepository;
use Stead\Backups\BackupRunner;
use Stead\Backups\BackupStatus;
use Stead\Backups\BackupTrigger;
use Stead\Backups\Dump\DumperFactory;
use Stead\Backups\Storage\StorageTargetFactory;
use Stead\Config\Configuration;
use Stead\Config\EnvWriter;
use Stead\Database\Connection;
use Stead\Exception\SafeException;
use Stead\View\Renderer;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin controller for backups: settings (frequency, retention, target),
 * history list, and the entry point for triggering manual backups and
 * restores from the UI.
 */
final class BackupAdminController
{
    public function __construct(
        private readonly Renderer $renderer,
        private readonly Configuration $config,
        private readonly Connection $connection,
        private readonly BackupRepository $backups,
        private readonly BackupRunner $runner,
    ) {
    }

    /**
     * @param array<string, string> $parameters
     */
    public function index(Request $request, array $parameters = []): Response
    {
        $actor = $this->requireAdmin($request);
        $target = strtolower($this->config->getString('backups.target', 'local'));
        $targets = ['local', 's3'];
        $history = $this->backups->listAll(50);
        $settings = [
            'target' => $target,
            'retention_count' => $this->config->getInt('backups.retention_count', 7),
            'frequency_hours' => $this->config->getInt('backups.frequency_hours', 24),
            's3_endpoint' => $this->config->getString('backups.s3.endpoint'),
            's3_bucket' => $this->config->getString('backups.s3.bucket'),
            's3_region' => $this->config->getString('backups.s3.region', 'us-east-1'),
        ];

        $flash = $request->attributes->get('flash');
        $error = $request->attributes->get('error');

        return $this->html($this->renderer->render('admin/backups/index', [
            'user_name' => $actor->name,
            'user_role' => $actor->roleName,
            'targets' => $targets,
            'settings' => $settings,
            'history' => $this->presentHistory($history),
            'flash' => is_string($flash) ? $flash : '',
            'error' => is_string($error) ? $error : '',
            'action_url' => '/admin/backups',
            'run_url' => '/admin/backups/run',
            'restore_url' => '/admin/backups/restore',
        ]));
    }

    /**
     * @param array<string, string> $parameters
     */
    public function save(Request $request, array $parameters = []): Response
    {
        $this->requireAdmin($request);

        $target = strtolower((string) $request->request->get('target', 'local'));
        $retentionRaw = (string) $request->request->get('retention_count', '7');
        $frequencyRaw = (string) $request->request->get('frequency_hours', '24');

        $errors = [];
        if (!in_array($target, ['local', 's3'], true)) {
            $errors[] = 'Storage target must be "local" or "s3".';
        }
        if (!ctype_digit($retentionRaw) || (int) $retentionRaw < 1) {
            $errors[] = 'Retention count must be a positive integer.';
        }
        if (!ctype_digit($frequencyRaw) || (int) $frequencyRaw < 0) {
            $errors[] = 'Frequency hours must be zero or a positive integer.';
        }
        if ($errors !== []) {
            $request->attributes->set('error', implode(' ', $errors));
            return $this->index($request, $parameters);
        }

        $envPath = $this->config->projectRoot() . '/.env';
        try {
            EnvWriter::write($envPath, [
                'BACKUPS_TARGET' => $target,
                'BACKUPS_RETENTION_COUNT' => (string) (int) $retentionRaw,
                'BACKUPS_FREQUENCY_HOURS' => (string) (int) $frequencyRaw,
            ]);
        } catch (\Throwable $e) {
            $request->attributes->set('error', 'Could not save settings: ' . $e->getMessage());
            return $this->index($request, $parameters);
        }

        return new RedirectResponse(
            '/admin/backups?flash=' . urlencode('Settings saved. New values apply on the next request.'),
            Response::HTTP_SEE_OTHER,
        );
    }

    /**
     * @param array<string, string> $parameters
     */
    public function run(Request $request, array $parameters = []): Response
    {
        $this->requireAdmin($request);

        try {
            $backup = $this->runner->run(BackupTrigger::MANUAL);
            return new RedirectResponse(
                '/admin/backups?flash=' . urlencode(sprintf(
                    'Backup #%d created (%s).',
                    $backup->id(),
                    self::formatBytes($backup->sizeBytes()),
                )),
                Response::HTTP_SEE_OTHER,
            );
        } catch (\Throwable $e) {
            $message = $e instanceof SafeException ? $e->publicMessage() : $e->getMessage();
            return new RedirectResponse(
                '/admin/backups?error=' . urlencode('Backup failed: ' . $message),
                Response::HTTP_SEE_OTHER,
            );
        }
    }

    /**
     * @param array<string, string> $parameters
     */
    public function confirmRestore(Request $request, array $parameters = []): Response
    {
        $actor = $this->requireAdmin($request);

        $id = (int) ($parameters['id'] ?? 0);
        $backup = $id > 0 ? $this->backups->find($id) : null;
        if ($backup === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }
        if ($backup->status() !== BackupStatus::SUCCESS) {
            return new RedirectResponse(
                '/admin/backups?error=' . urlencode('Only successful backups can be restored.'),
                Response::HTTP_SEE_OTHER,
            );
        }

        return $this->html($this->renderer->render('admin/backups/confirm-restore', [
            'user_name' => $actor->name,
            'user_role' => $actor->roleName,
            'backup' => [
                'id' => $backup->id(),
                'target' => $backup->target(),
                'size_human' => self::formatBytes($backup->sizeBytes()),
                'created_at' => $backup->createdAt(),
            ],
            'restore_url' => '/admin/backups/restore',
        ]));
    }

    /**
     * @param array<string, string> $parameters
     */
    public function restore(Request $request, array $parameters = []): Response
    {
        $this->requireAdmin($request);

        $id = (int) $request->request->get('id', 0);
        if ($id <= 0) {
            return new RedirectResponse(
                '/admin/backups?error=' . urlencode('Missing backup id.'),
                Response::HTTP_SEE_OTHER,
            );
        }
        $backup = $this->backups->find($id);
        if ($backup === null) {
            return new RedirectResponse(
                '/admin/backups?error=' . urlencode('No backup found with that id.'),
                Response::HTTP_SEE_OTHER,
            );
        }
        if ($backup->status() !== BackupStatus::SUCCESS) {
            return new RedirectResponse(
                '/admin/backups?error=' . urlencode('Only successful backups can be restored.'),
                Response::HTTP_SEE_OTHER,
            );
        }

        // Explicit confirmation step, mirroring `bin/backup:restore`'s
        // typed-"yes" requirement: the restore form is only reachable
        // via confirmRestore()'s GET page, which is the one place this
        // field is set. A POST missing it (bypassing the confirm page)
        // is refused without touching the DB or media directory.
        if ($request->request->get('confirm') !== (string) $id) {
            return new RedirectResponse(
                '/admin/backups?error=' . urlencode('Restore was not confirmed.'),
                Response::HTTP_SEE_OTHER,
            );
        }

        $runner = new \Stead\Backups\Restore\RestoreRunner(
            $this->config,
            $this->connection,
            $this->backups,
            new StorageTargetFactory($this->config),
            $this->resolveMediaRoot($this->config),
        );

        try {
            $runner->restore($backup);
        } catch (\Throwable $e) {
            $message = $e instanceof SafeException ? $e->publicMessage() : $e->getMessage();
            return new RedirectResponse(
                '/admin/backups?error=' . urlencode('Restore failed: ' . $message),
                Response::HTTP_SEE_OTHER,
            );
        }
        return new RedirectResponse(
            '/admin/backups?flash=' . urlencode('Restore from backup #' . $id . ' complete.'),
            Response::HTTP_SEE_OTHER,
        );
    }

    /**
     * @param list<Backup> $history
     * @return list<array<string, mixed>>
     */
    private function presentHistory(array $history): array
    {
        $out = [];
        foreach ($history as $backup) {
            $out[] = [
                'id' => $backup->id(),
                'target' => $backup->target(),
                'status' => $backup->status(),
                'size_bytes' => $backup->sizeBytes(),
                'size_human' => self::formatBytes($backup->sizeBytes()),
                'triggered_by' => $backup->triggeredBy(),
                'created_at' => $backup->createdAt(),
                'error_message' => $backup->errorMessage() ?? '',
                'restorable' => $backup->status() === BackupStatus::SUCCESS,
            ];
        }
        return $out;
    }

    private function requireAdmin(Request $request): User
    {
        $user = $request->attributes->get('user');
        if (!$user instanceof User || !$user->isAdmin()) {
            throw new SafeException('Admin required.');
        }
        return $user;
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

    private function html(string $body): Response
    {
        return new Response(
            $body,
            Response::HTTP_OK,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
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
