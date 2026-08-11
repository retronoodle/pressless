<?php

declare(strict_types=1);

namespace Stead\Http\Controller;

use Stead\Content\Collection;
use Stead\Content\CollectionRepository;
use Stead\Content\Entry;
use Stead\Content\EntryRepository;
use Stead\View\Renderer;
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
    ) {
    }

    /**
     * @param array<string, string> $parameters
     */
    public function home(Request $request, array $parameters = []): Response
    {
        $collections = $this->collections->all();

        return $this->html($this->renderer->render('home', [
            'collections' => $collections,
        ]));
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
        $listing = $this->entries->listByCollectionPaged($collection->id(), $page);

        return $this->html($this->renderer->render('collection', [
            'collection' => $collection,
            'entries' => $listing['entries'],
            'page' => $listing['page'],
            'has_next' => $listing['has_next'],
            'total' => $listing['total'],
            'page_size' => $listing['page_size'],
        ]));
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
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $entry = $this->entries->findByCollectionAndSlug($collection->id(), $entrySlug);
        if ($entry === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        return $this->html($this->renderer->render('entry', [
            'collection' => $collection,
            'entry' => $entry,
        ]));
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

    private function html(string $body): Response
    {
        return new Response(
            $body,
            Response::HTTP_OK,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
    }
}