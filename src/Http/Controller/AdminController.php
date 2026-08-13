<?php

declare(strict_types=1);

namespace Stead\Http\Controller;

use Stead\Auth\AuthorizationService;
use Stead\Auth\LoginAttemptRepository;
use Stead\Auth\User;
use Stead\Content\Collection;
use Stead\Content\CollectionRepository;
use Stead\Content\EntryRepository;
use Stead\Content\RevisionRepository;
use Stead\Update\UpdateChecker;
use Stead\Update\UpdateCheckResult;
use Stead\View\Renderer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders the authenticated admin shell.
 *
 * The route is wrapped by the authentication guard, which sets the `user`
 * request attribute before this handler runs. The dashboard view reports
 * collection and entry counts so the empty-state CTA appears only when the
 * site is genuinely empty.
 *
 * On each request, the update checker is consulted (cached, so no
 * network call on every page load). When a newer release is available the
 * dashboard surfaces a banner that links to the manual-update page.
 */
final class AdminController
{
    private const RECENT_ACTIVITY_LIMIT = 5;

    public function __construct(
        private readonly Renderer $renderer,
        private readonly CollectionRepository $collections,
        private readonly EntryRepository $entries,
        private readonly AuthorizationService $authorization,
        private readonly UpdateChecker $updateChecker,
        private readonly RevisionRepository $revisions,
        private readonly LoginAttemptRepository $loginAttempts,
    ) {
    }

    /**
     * @param array<string, string> $parameters
     */
    public function index(Request $request, array $parameters = []): Response
    {
        $user = $request->attributes->get('user');

        $visibleSlugs = $user instanceof User
            ? $this->authorization->grantedCollectionSlugs($user)
            : [];
        $collections = $this->visibleCollections($visibleSlugs);

        $updateResult = $this->safeUpdateCheck();

        return new Response(
            $this->renderer->render('admin', [
                'user_name' => $user instanceof User ? $user->name : '',
                'user_role' => $user instanceof User ? $user->roleName : '',
                'collection_count' => count($collections),
                'entry_count' => $this->entries->count(),
                'visible_collections' => $collections,
                'update_notice' => $this->buildUpdateNotice($updateResult),
                'installed_version' => $this->installedVersionString($updateResult),
                'installed_version_released_at' => $updateResult?->installedVersionReleasedAt ?? '',
                'recent_revisions' => $this->safeRecentRevisions(),
                'recent_logins' => $this->safeRecentLogins(),
            ]),
            Response::HTTP_OK,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
    }

    /**
     * Runs the update check behind a try/catch so an unexpected failure
     * in update-checking code never breaks the admin dashboard. This is
     * a second line of defence on top of UpdateChecker's own fail-closed
     * behaviour — the controller layer is the last thing standing
     * between a misbehaving update path and an admin-facing 500.
     */
    private function safeUpdateCheck(): ?UpdateCheckResult
    {
        try {
            return $this->updateChecker->check();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function safeRecentRevisions(): array
    {
        try {
            return $this->revisions->listRecent(self::RECENT_ACTIVITY_LIMIT);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function safeRecentLogins(): array
    {
        try {
            return $this->loginAttempts->listRecentSuccesses(self::RECENT_ACTIVITY_LIMIT);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @return array{available: bool, latest: string, installed: string, url: string}|null
     */
    private function buildUpdateNotice(?UpdateCheckResult $result): ?array
    {
        if ($result === null || !$result->hasUpdate()) {
            return null;
        }
        $downloadUrl = $result->downloadUrl ?? '';
        if ($downloadUrl === '') {
            // Has-update but no URL → don't show a banner that points
            // nowhere. Treat as no update from the UI's perspective.
            return null;
        }
        return [
            'available' => true,
            'latest' => (string) $result->latestVersion,
            'installed' => $result->installedVersion,
            'url' => $downloadUrl,
        ];
    }

    /**
     * Resolves the version string to surface in the admin header. The
     * value comes from the same `UpdateCheckResult` the checker used to
     * read the VERSION file (so we don't read VERSION from the
     * template); if the check itself errored out, fall back to "version
     * unknown" rather than rendering nothing — the spec requires the
     * segment to always be present.
     */
    private function installedVersionString(?UpdateCheckResult $result): string
    {
        if ($result === null || $result->installedVersion === '' || $result->installedVersion === 'unknown') {
            return 'version unknown';
        }
        return $result->installedVersion;
    }

    /**
     * @param list<string> $visibleSlugs
     * @return list<array{slug: string, name: string, field_count: int}>
     */
    private function visibleCollections(array $visibleSlugs): array
    {
        if ($visibleSlugs === []) {
            return [];
        }
        $rows = [];
        foreach ($this->collections->all() as $collection) {
            if (!in_array($collection->slug(), $visibleSlugs, true)) {
                continue;
            }
            $rows[] = [
                'slug' => $collection->slug(),
                'name' => $collection->name(),
                'field_count' => count($collection->fields()),
            ];
        }
        return $rows;
    }
}
