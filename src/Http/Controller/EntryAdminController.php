<?php

declare(strict_types=1);

namespace Stead\Http\Controller;

use Stead\Auth\User;
use Stead\Content\Collection;
use Stead\Content\CollectionRepository;
use Stead\Content\Entry;
use Stead\Content\EntryRepository;
use Stead\Content\EntryValidator;
use Stead\Content\FieldType\FieldTypeRegistry;
use Stead\Content\RevisionRepository;
use Stead\Content\SlugGenerator;
use Stead\Exception\SafeException;
use Stead\Http\Cache\CollectionVersionStore;
use Stead\View\Renderer;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thin admin controller for entry CRUD.
 *
 * Validation lives in {@see EntryValidator}, persistence in
 * {@see EntryRepository}; this class only orchestrates them and renders the
 * resulting forms.
 */
final class EntryAdminController
{
    public function __construct(
        private readonly Renderer $renderer,
        private readonly CollectionRepository $collections,
        private readonly EntryRepository $entries,
        private readonly EntryValidator $validator,
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly SlugGenerator $slugs,
        private readonly CollectionVersionStore $versions,
        private readonly RevisionRepository $revisions,
    ) {
    }

    /**
     * @param array<string, string> $parameters
     */
    public function index(Request $request, array $parameters = []): Response
    {
        $slug = (string) ($parameters['slug'] ?? '');
        $collection = $this->collections->findBySlug($slug);
        if ($collection === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $user = $request->attributes->get('user');
        $previewKey = $this->slugSourceKey($collection);
        $rows = [];
        foreach ($this->entries->listByCollection($collection->id()) as $entry) {
            $rows[] = [
                'id' => $entry->id(),
                'slug' => $entry->slug(),
                'status' => $entry->status(),
                'preview' => $this->preview($entry, $previewKey),
            ];
        }

        return $this->html($this->renderer->render('admin/entries/index', [
            'user_name' => $user instanceof User ? $user->name : '',
            'collection' => $collection,
            'entries' => $rows,
        ]));
    }

    /**
     * @param array<string, string> $parameters
     */
    public function create(Request $request, array $parameters = []): Response
    {
        $slug = (string) ($parameters['slug'] ?? '');
        $collection = $this->collections->findBySlug($slug);
        if ($collection === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $payload = $this->extractPayload($request);
        return $this->renderForm(
            $request,
            $collection,
            new Entry(0, $collection->id(), '', []),
            $payload['values'],
            [],
            isEdit: false,
            actionUrl: '/admin/collections/' . rawurlencode($slug) . '/entries/new',
            cancelUrl: '/admin/collections/' . rawurlencode($slug),
        );
    }

    /**
     * @param array<string, string> $parameters
     */
    public function store(Request $request, array $parameters = []): Response
    {
        $slug = (string) ($parameters['slug'] ?? '');
        $collection = $this->collections->findBySlug($slug);
        if ($collection === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $payload = $this->extractPayload($request);
        $result = $this->validator->validate($collection, $payload['values']);
        if ($result->hasErrors()) {
            return $this->renderForm(
                $request,
                $collection,
                new Entry(0, $collection->id(), '', []),
                $payload['values'],
                $result->errors(),
                isEdit: false,
                actionUrl: '/admin/collections/' . rawurlencode($slug) . '/entries/new',
                cancelUrl: '/admin/collections/' . rawurlencode($slug),
            );
        }

        try {
            $saved = $this->entries->save(
                new Entry(0, $collection->id(), '', []),
                $collection,
                $payload['values'],
                $this->authorIdFromRequest($request),
            );
        } catch (SafeException $e) {
            return $this->renderForm(
                $request,
                $collection,
                new Entry(0, $collection->id(), '', []),
                $payload['values'],
                ['__schema' => [$e->publicMessage()]],
                isEdit: false,
                actionUrl: '/admin/collections/' . rawurlencode($slug) . '/entries/new',
                cancelUrl: '/admin/collections/' . rawurlencode($slug),
            );
        }

        $this->versions->bump($collection->id());

        return new RedirectResponse(
            '/admin/collections/' . rawurlencode($slug) . '/entries/' . $saved->id() . '/edit',
            Response::HTTP_SEE_OTHER,
        );
    }

    /**
     * @param array<string, string> $parameters
     */
    public function edit(Request $request, array $parameters = []): Response
    {
        $slug = (string) ($parameters['slug'] ?? '');
        $id = (int) ($parameters['id'] ?? 0);
        $collection = $this->collections->findBySlug($slug);
        if ($collection === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }
        $entry = $this->findOwnedEntry($collection, $id);
        if ($entry === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        return $this->renderForm(
            $request,
            $collection,
            $entry,
            $entry->values(),
            [],
            isEdit: true,
            actionUrl: '/admin/collections/' . rawurlencode($slug) . '/entries/' . $id . '/edit',
            cancelUrl: '/admin/collections/' . rawurlencode($slug),
        );
    }

    /**
     * @param array<string, string> $parameters
     */
    public function update(Request $request, array $parameters = []): Response
    {
        $slug = (string) ($parameters['slug'] ?? '');
        $id = (int) ($parameters['id'] ?? 0);
        $collection = $this->collections->findBySlug($slug);
        if ($collection === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }
        $existing = $this->findOwnedEntry($collection, $id);
        if ($existing === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $payload = $this->extractPayload($request);
        $result = $this->validator->validate($collection, $payload['values']);
        if ($result->hasErrors()) {
            return $this->renderForm(
                $request,
                $collection,
                $existing,
                $payload['values'],
                $result->errors(),
                isEdit: true,
                actionUrl: '/admin/collections/' . rawurlencode($slug) . '/entries/' . $id . '/edit',
                cancelUrl: '/admin/collections/' . rawurlencode($slug),
            );
        }

        try {
            $saved = $this->entries->save($existing, $collection, $payload['values'], $this->authorIdFromRequest($request));
        } catch (SafeException $e) {
            return $this->renderForm(
                $request,
                $collection,
                $existing,
                $payload['values'],
                ['__schema' => [$e->publicMessage()]],
                isEdit: true,
                actionUrl: '/admin/collections/' . rawurlencode($slug) . '/entries/' . $id . '/edit',
                cancelUrl: '/admin/collections/' . rawurlencode($slug),
            );
        }

        $this->versions->bump($collection->id());

        return new RedirectResponse(
            '/admin/collections/' . rawurlencode($slug) . '/entries/' . $saved->id() . '/edit',
            Response::HTTP_SEE_OTHER,
        );
    }

    /**
     * @param array<string, string> $parameters
     */
    public function destroy(Request $request, array $parameters = []): Response
    {
        $slug = (string) ($parameters['slug'] ?? '');
        $id = (int) ($parameters['id'] ?? 0);
        $collection = $this->collections->findBySlug($slug);
        if ($collection === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }
        $entry = $this->findOwnedEntry($collection, $id);
        if ($entry === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $this->entries->delete($entry->id());
        $this->versions->bump($collection->id());

        return new RedirectResponse(
            '/admin/collections/' . rawurlencode($slug),
            Response::HTTP_SEE_OTHER,
        );
    }

    /**
     * @param array<string, string> $parameters
     */
    public function publish(Request $request, array $parameters = []): Response
    {
        $slug = (string) ($parameters['slug'] ?? '');
        $id = (int) ($parameters['id'] ?? 0);
        $collection = $this->collections->findBySlug($slug);
        if ($collection === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }
        $entry = $this->findOwnedEntry($collection, $id);
        if ($entry === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $this->entries->publish($entry->id());
        $this->versions->bump($collection->id());

        return new RedirectResponse(
            '/admin/collections/' . rawurlencode($slug) . '/entries/' . $id . '/edit',
            Response::HTTP_SEE_OTHER,
        );
    }

    /**
     * @param array<string, string> $parameters
     */
    public function unpublish(Request $request, array $parameters = []): Response
    {
        $slug = (string) ($parameters['slug'] ?? '');
        $id = (int) ($parameters['id'] ?? 0);
        $collection = $this->collections->findBySlug($slug);
        if ($collection === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }
        $entry = $this->findOwnedEntry($collection, $id);
        if ($entry === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $this->entries->unpublish($entry->id());
        $this->versions->bump($collection->id());

        return new RedirectResponse(
            '/admin/collections/' . rawurlencode($slug) . '/entries/' . $id . '/edit',
            Response::HTTP_SEE_OTHER,
        );
    }

    /**
     * @param array<string, string> $parameters
     */
    public function revisions(Request $request, array $parameters = []): Response
    {
        $slug = (string) ($parameters['slug'] ?? '');
        $id = (int) ($parameters['id'] ?? 0);
        $collection = $this->collections->findBySlug($slug);
        if ($collection === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }
        $entry = $this->findOwnedEntry($collection, $id);
        if ($entry === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $user = $request->attributes->get('user');
        $revisions = $this->revisions->listByEntry($entry->id());

        return $this->html($this->renderer->render('admin/entries/revisions', [
            'user_name' => $user instanceof User ? $user->name : '',
            'collection' => $collection,
            'entry' => $entry,
            'revisions' => $revisions,
            'back_url' => '/admin/collections/' . rawurlencode($slug) . '/entries/' . $id . '/edit',
        ]));
    }

    /**
     * @param array<string, string> $parameters
     */
    public function restore(Request $request, array $parameters = []): Response
    {
        $slug = (string) ($parameters['slug'] ?? '');
        $id = (int) ($parameters['id'] ?? 0);
        $revisionId = (int) ($parameters['revisionId'] ?? 0);
        $collection = $this->collections->findBySlug($slug);
        if ($collection === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }
        $entry = $this->findOwnedEntry($collection, $id);
        if ($entry === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }
        $revision = $this->revisions->find($revisionId);
        if ($revision === null || (int) ($revision['entry_id'] ?? 0) !== $entry->id()) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $payload = is_array($revision['payload'] ?? null) ? $revision['payload'] : [];
        $values = is_array($payload['values'] ?? null) ? $payload['values'] : [];
        $result = $this->validator->validate($collection, $values);
        if ($result->hasErrors()) {
            return $this->renderForm(
                $request,
                $collection,
                $entry,
                $values,
                $result->errors(),
                isEdit: true,
                actionUrl: '/admin/collections/' . rawurlencode($slug) . '/entries/' . $id . '/edit',
                cancelUrl: '/admin/collections/' . rawurlencode($slug),
            );
        }

        try {
            $this->entries->save($entry, $collection, $values, $this->authorIdFromRequest($request));
        } catch (SafeException $e) {
            return $this->renderForm(
                $request,
                $collection,
                $entry,
                $values,
                ['__schema' => [$e->publicMessage()]],
                isEdit: true,
                actionUrl: '/admin/collections/' . rawurlencode($slug) . '/entries/' . $id . '/edit',
                cancelUrl: '/admin/collections/' . rawurlencode($slug),
            );
        }

        $this->versions->bump($collection->id());

        return new RedirectResponse(
            '/admin/collections/' . rawurlencode($slug) . '/entries/' . $id . '/edit',
            Response::HTTP_SEE_OTHER,
        );
    }

    /**
     * @param array<string, mixed>       $values
     * @param array<string, list<string>> $errors
     */
    private function renderForm(
        Request $request,
        Collection $collection,
        Entry $entry,
        array $values,
        array $errors,
        bool $isEdit,
        string $actionUrl,
        string $cancelUrl,
    ): Response {
        $user = $request->attributes->get('user');

        $slugPreview = null;
        if ($entry->slug() !== '') {
            $slugPreview = $entry->slug();
        } else {
            $candidate = $this->slugs->generate($this->stringify($values[$this->slugSourceKey($collection)] ?? ''));
            if ($candidate !== '') {
                $slugPreview = $candidate . ' (preview)';
            }
        }

        return $this->html($this->renderer->render('admin/entries/form', [
            'user_name' => $user instanceof User ? $user->name : '',
            'collection' => $collection,
            'entry' => $entry,
            'fields' => $collection->fields(),
            'field_types' => $this->fieldTypes,
            'values' => $values,
            'errors' => $errors,
            'is_edit' => $isEdit,
            'action_url' => $actionUrl,
            'cancel_url' => $cancelUrl,
            'slug_preview' => $slugPreview,
            'form_title' => $isEdit
                ? sprintf('Edit entry #%d in %s', $entry->id(), $collection->name())
                : sprintf('New entry in %s', $collection->name()),
            'publish_url' => $isEdit && $entry->id() !== 0
                ? '/admin/collections/' . rawurlencode($collection->slug()) . '/entries/' . $entry->id() . '/publish'
                : null,
            'unpublish_url' => $isEdit && $entry->id() !== 0
                ? '/admin/collections/' . rawurlencode($collection->slug()) . '/entries/' . $entry->id() . '/unpublish'
                : null,
            'revisions_url' => $isEdit && $entry->id() !== 0
                ? '/admin/collections/' . rawurlencode($collection->slug()) . '/entries/' . $entry->id() . '/revisions'
                : null,
        ]));
    }

    /**
     * @return array{values: array<string, mixed>}
     */
    private function extractPayload(Request $request): array
    {
        $raw = $request->request->all('fields');
        $values = [];
        if (is_array($raw)) {
            foreach ($raw as $key => $value) {
                if (!is_string($key) || $key === '') {
                    continue;
                }
                $values[$key] = $value;
            }
        }
        return ['values' => $values];
    }

    private function findOwnedEntry(Collection $collection, int $id): ?Entry
    {
        $entry = $this->entries->find($id);
        if ($entry === null) {
            return null;
        }
        if ($entry->collectionId() !== $collection->id()) {
            return null;
        }
        return $entry;
    }

    private function authorIdFromRequest(Request $request): ?int
    {
        $user = $request->attributes->get('user');
        if (!$user instanceof User) {
            return null;
        }
        return $user->id > 0 ? $user->id : null;
    }

    private function preview(Entry $entry, string $previewKey): string
    {
        if ($previewKey !== '') {
            $value = $entry->value($previewKey);
            if (is_string($value) && $value !== '') {
                return mb_substr($value, 0, 80);
            }
        }
        foreach ($entry->values() as $value) {
            if (is_string($value) && $value !== '') {
                return mb_substr($value, 0, 80);
            }
        }
        return '';
    }

    private function slugSourceKey(Collection $collection): string
    {
        foreach ($collection->fields() as $field) {
            if (!is_array($field)) {
                continue;
            }
            $type = (string) ($field['type'] ?? '');
            $key = (string) ($field['key'] ?? '');
            if (($type === 'text' || $type === 'richtext' || $type === 'select') && $key !== '') {
                return $key;
            }
        }
        return '';
    }

    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        return '';
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