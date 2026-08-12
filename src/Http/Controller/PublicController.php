<?php

declare(strict_types=1);

namespace Stead\Http\Controller;

use Stead\Content\CollectionRepository;
use Stead\Content\EntryRepository;
use Stead\Content\RedirectRepository;
use Stead\Http\Cache\CollectionVersionStore;
use Stead\Http\Cache\PageCache;
use Stead\View\Renderer;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders public-facing pages for a collection's listing and individual
 * entries. There is no authentication here — these routes are mounted last
 * in the route table so admin paths win.
 *
 * Resolution matches the route table: unknown collection or entry slugs
 * return 404 so the existing Kernel error path produces the public 404
 * page.
 */
final class PublicController
{
    public function __construct(
        private readonly Renderer $renderer,
        private readonly CollectionRepository $collections,
        private readonly EntryRepository $entries,
        private readonly PageCache $pageCache,
        private readonly CollectionVersionStore $versions,
        private readonly RedirectRepository $redirects,
    ) {
    }

    /**
     * @param array<string, string> $parameters
     */
    public function home(Request $request, array $parameters = []): Response
    {
        $collections = $this->collections->all();
        $key = 'home|' . $this->homeVersionsKey($collections);

        $html = $this->pageCache->remember($key, function () use ($collections): string {
            return $this->renderer->render('home', [
                'collections' => $collections,
            ]);
        });

        return $this->html($html);
    }

    /**
     * @param array<string, string> $parameters
     */
    public function collection(Request $request, array $parameters = []): Response
    {
        $slug = (string) ($parameters['collectionSlug'] ?? '');
        $collection = $this->collections->findBySlug($slug);
        if ($collection === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $page = $this->resolvePage($request);
        $key = sprintf(
            'collection|%s|%d|%d',
            $collection->slug(),
            $this->versions->get($collection->id()),
            $page,
        );

        $html = $this->pageCache->remember($key, function () use ($collection, $page): string {
            $listing = $this->entries->listByCollectionPaged($collection->id(), $page, EntryRepository::STATUS_PUBLISHED);
            return $this->renderer->render('collection', [
                'collection' => $collection,
                'entries' => $listing['entries'],
                'page' => $listing['page'],
                'has_next' => $listing['has_next'],
                'total' => $listing['total'],
                'page_size' => $listing['page_size'],
            ]);
        });

        return $this->html($html);
    }

    /**
     * @param array<string, string> $parameters
     */
    public function entry(Request $request, array $parameters = []): Response
    {
        $collectionSlug = (string) ($parameters['collectionSlug'] ?? '');
        $entrySlug = (string) ($parameters['entrySlug'] ?? '');

        $collection = $this->collections->findBySlug($collectionSlug);
        if ($collection === null) {
            return $this->redirectOrNotFound($collectionSlug, $entrySlug);
        }

        $entry = $this->entries->findByCollectionAndSlug($collection->id(), $entrySlug, EntryRepository::STATUS_PUBLISHED);
        if ($entry === null) {
            return $this->redirectOrNotFound($collectionSlug, $entrySlug);
        }

        $key = sprintf(
            'entry|%s|%s|%d',
            $collection->slug(),
            $entry->slug(),
            $this->versions->get($collection->id()),
        );

        $html = $this->pageCache->remember($key, function () use ($collection, $entry): string {
            return $this->renderer->render('entry', [
                'collection' => $collection,
                'entry' => $entry,
            ]);
        });

        return $this->html($html);
    }

    /**
     * Looks up the requested public entry path in `redirects`. Returns an
     * HTTP 301 to the new path on a match; otherwise returns the existing
     * 404 empty response. The live entry lookup above has already failed,
     * so a redirect row is the only remaining fallback.
     */
    private function redirectOrNotFound(string $collectionSlug, string $entrySlug): Response
    {
        $requestedPath = '/' . ltrim($collectionSlug, '/') . '/' . ltrim($entrySlug, '/');
        $redirect = $this->redirects->findByOldPath($requestedPath);
        if ($redirect !== null) {
            return new RedirectResponse($redirect->newPath(), Response::HTTP_MOVED_PERMANENTLY);
        }
        return new Response('', Response::HTTP_NOT_FOUND);
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

    /**
     * @param list<\Stead\Content\Collection> $collections
     */
    private function homeVersionsKey(array $collections): string
    {
        $parts = [];
        foreach ($collections as $collection) {
            $parts[] = $collection->id() . ':' . $this->versions->get($collection->id());
        }
        return implode('|', $parts);
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