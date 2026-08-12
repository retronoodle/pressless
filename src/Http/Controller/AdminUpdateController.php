<?php

declare(strict_types=1);

namespace Stead\Http\Controller;

use Stead\Update\UpdateChecker;
use Stead\View\Renderer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders the manual-update instructions page reachable from the admin
 * dashboard's update banner.
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
    ) {
    }

    /**
     * @param array<string, string> $parameters
     */
    public function show(Request $request, array $parameters = []): Response
    {
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

        return new Response(
            $this->renderer->render('admin/update', [
                'available' => $available,
                'latest_version' => $latest,
                'installed_version' => $installed,
                'download_url' => $downloadUrl,
            ]),
            Response::HTTP_OK,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
    }
}
