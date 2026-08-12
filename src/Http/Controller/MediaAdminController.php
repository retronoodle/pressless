<?php

declare(strict_types=1);

namespace Stead\Http\Controller;

use Stead\Auth\User;
use Stead\Config\Configuration;
use Stead\Media\LocalStorage;
use Stead\Media\MediaRepository;
use Stead\View\Renderer;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thin admin controller for the media library.
 *
 * The index action lists uploaded files and renders the upload form. The
 * upload action handles the multipart POST, validates mime + size against
 * the configured allow-list, stores the file, and creates a `media` row.
 *
 * Validation errors are passed back into the same index template via the
 * `errors` map so the form is re-rendered with the failure visible (no
 * flash layer needed for a single-page admin form).
 */
final class MediaAdminController
{
    /** @var list<string> */
    public const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    public function __construct(
        private readonly Renderer $renderer,
        private readonly MediaRepository $media,
        private readonly LocalStorage $storage,
        private readonly Configuration $config,
    ) {
    }

    /**
     * @param array<string, string> $parameters
     */
    public function index(Request $request, array $parameters = []): Response
    {
        return $this->renderIndex(
            $request,
            errors: [],
            success: '',
        );
    }

    /**
     * @param array<string, string> $parameters
     */
    public function upload(Request $request, array $parameters = []): Response
    {
        $user = $request->attributes->get('user');
        $userId = $user instanceof User ? $user->id : null;

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return $this->renderIndex(
                $request,
                errors: ['Please choose a file to upload.'],
                success: '',
            );
        }

        $maxBytes = $this->config->getInt('media.max_size_bytes', 10 * 1024 * 1024);
        if ($maxBytes > 0 && $file->getSize() !== false && $file->getSize() > $maxBytes) {
            return $this->renderIndex(
                $request,
                errors: [sprintf(
                    'File is too large. Maximum allowed size is %s.',
                    $this->formatBytes($maxBytes),
                )],
                success: '',
            );
        }

        $detectedMime = $this->detectMime($file);
        if ($detectedMime === null || !in_array($detectedMime, self::ALLOWED_MIME_TYPES, true)) {
            return $this->renderIndex(
                $request,
                errors: ['File type not allowed. Allowed types: JPEG, PNG, GIF, WebP.'],
                success: '',
            );
        }

        $path = $file->getPathname();
        if (!is_string($path) || $path === '' || !is_file($path) || !$file->isValid()) {
            return $this->renderIndex(
                $request,
                errors: ['Uploaded file could not be read.'],
                success: '',
            );
        }

        $sizeBytes = (int) $file->getSize();
        $originalName = (string) $file->getClientOriginalName();
        $safeName = $originalName !== '' ? $originalName : 'upload';

        $reserved = $this->media->create([
            'filename' => $safeName,
            'mime_type' => $detectedMime,
            'size_bytes' => $sizeBytes,
            'path' => 'pending',
            'uploaded_by' => $userId,
        ]);

        try {
            $relativePath = $this->moveUpload($file, $reserved->id(), $safeName);
        } catch (\Throwable $e) {
            $this->media->delete($reserved->id());
            return $this->renderIndex(
                $request,
                errors: ['Upload failed: ' . $e->getMessage()],
                success: '',
            );
        }

        $this->media->updatePath($reserved->id(), $relativePath);

        return new RedirectResponse('/admin/media', Response::HTTP_SEE_OTHER);
    }

    /**
     * Moves the uploaded file into the media storage directory for the
     * given id. Uses {@see UploadedFile::move()} when the file is a real
     * upload (the move is tracked on the object so subsequent reads
     * point to the new location), and falls back to a copy+unlink for the
     * synthetic uploads used in tests where `move()` is a no-op.
     */
    private function moveUpload(UploadedFile $file, int $mediaId, string $originalName): string
    {
        $directory = $this->storage->directoryFor($mediaId);
        $this->ensureDirectory($directory);
        $safeName = $this->sanitizeFilename($originalName);
        $target = $directory . '/' . $safeName;

        $sourcePath = $file->getPathname();
        if ($file->isValid() && is_uploaded_file($sourcePath)) {
            $file->move($directory, $safeName);
            return $mediaId . '/' . $safeName;
        }

        if (!is_string($sourcePath) || !is_file($sourcePath)) {
            throw new \Stead\Exception\SafeException('Uploaded file could not be read.');
        }
        if (!@copy($sourcePath, $target)) {
            throw new \Stead\Exception\SafeException(sprintf('Could not move uploaded file to "%s".', $target));
        }
        return $mediaId . '/' . $safeName;
    }

    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }
        if (!@mkdir($path, 0775, true) && !is_dir($path)) {
            throw new \Stead\Exception\SafeException(sprintf('Could not create directory "%s".', $path));
        }
    }

    private function sanitizeFilename(string $filename): string
    {
        $base = basename($filename);
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', $base) ?? 'upload';
        if ($base === '' || $base === '.' || $base === '..') {
            $base = 'upload';
        }
        if (strlen($base) > 200) {
            $base = substr($base, 0, 200);
        }
        return $base;
    }

    /**
     * @param list<string> $errors
     */
    private function renderIndex(
        Request $request,
        array $errors,
        string $success,
    ): Response {
        $user = $request->attributes->get('user');
        $page = $this->resolvePage($request);
        $listing = $this->media->listPaged($page);

        return $this->html($this->renderer->render('admin/media/index', [
            'user_name' => $user instanceof User ? $user->name : '',
            'user_role' => $user instanceof User ? $user->roleName : '',
            'items' => $listing['items'],
            'page' => $listing['page'],
            'has_next' => $listing['has_next'],
            'total' => $listing['total'],
            'page_size' => $listing['page_size'],
            'errors' => $errors,
            'success' => $success,
        ]));
    }

    private function detectMime(UploadedFile $file): ?string
    {
        $path = $file->getPathname();
        if (!is_string($path) || !is_file($path)) {
            return null;
        }
        $detected = @finfo_open(FILEINFO_MIME_TYPE);
        if ($detected !== false) {
            $mime = @finfo_file($detected, $path);
            @finfo_close($detected);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }
        $imageInfo = @getimagesize($path);
        if (is_array($imageInfo) && is_string($imageInfo['mime'])) {
            return $imageInfo['mime'];
        }
        return null;
    }

    private function resolvePage(Request $request): int
    {
        $raw = $request->query->get('page', '1');
        if (!is_string($raw)) {
            return 1;
        }
        $page = (int) $raw;
        return $page < 1 ? 1 : $page;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return sprintf('%.1f MB', $bytes / (1024 * 1024));
        }
        if ($bytes >= 1024) {
            return sprintf('%.1f KB', $bytes / 1024);
        }
        return sprintf('%d B', $bytes);
    }

    private function html(string $body): Response
    {
        return new Response(
            $body,
            Response::HTTP_OK,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
    }
}
