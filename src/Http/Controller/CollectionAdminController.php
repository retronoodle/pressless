<?php

declare(strict_types=1);

namespace Stead\Http\Controller;

use Stead\Auth\User;
use Stead\Content\Collection;
use Stead\Content\CollectionRepository;
use Stead\Content\CollectionSchemaValidator;
use Stead\Content\FieldType\FieldTypeRegistry;
use Stead\Content\SchemaChangeHelper;
use Stead\Content\SchemaValidationException;
use Stead\Database\Connection;
use Stead\Exception\SafeException;
use Stead\View\Renderer;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thin admin controller for collection CRUD.
 *
 * The controller does no validation or persistence of its own. Schema
 * validation delegates to {@see CollectionSchemaValidator}, persistence to
 * {@see CollectionRepository}, and `entry_values` cleanup to
 * {@see SchemaChangeHelper}.
 */
final class CollectionAdminController
{
    public function __construct(
        private readonly Renderer $renderer,
        private readonly Connection $connection,
        private readonly CollectionRepository $collections,
        private readonly CollectionSchemaValidator $schemaValidator,
        private readonly SchemaChangeHelper $schemaChanges,
        private readonly FieldTypeRegistry $fieldTypes,
    ) {
    }

    /**
     * @param array<string, string> $parameters
     */
    public function index(Request $request, array $parameters = []): Response
    {
        $user = $request->attributes->get('user');

        $rows = [];
        foreach ($this->collections->all() as $collection) {
            $rows[] = [
                'slug' => $collection->slug(),
                'name' => $collection->name(),
                'field_count' => count($collection->fields()),
            ];
        }

        return $this->html($this->renderer->render('admin/collections/index', [
            'user_name' => $user instanceof User ? $user->name : '',
            'collections' => $rows,
        ]));
    }

    /**
     * @param array<string, string> $parameters
     */
    public function create(Request $request, array $parameters = []): Response
    {
        return $this->renderForm(
            $request,
            new Collection(0, '', '', ['fields' => [$this->emptyField()]]),
            [],
            isEdit: false,
            actionUrl: '/admin/collections/new',
        );
    }

    /**
     * @param array<string, string> $parameters
     */
    public function store(Request $request, array $parameters = []): Response
    {
        $payload = $this->extractPayload($request);

        if ($this->wantsRebuild($request)) {
            return $this->renderForm(
                $request,
                new Collection(
                    0,
                    $payload['slug'],
                    $payload['name'],
                    ['fields' => $this->rebuiltFieldList($payload['fields'], $request)],
                ),
                [],
                isEdit: false,
                actionUrl: '/admin/collections/new',
            );
        }

        $collection = new Collection(0, $payload['slug'], $payload['name'], ['fields' => $payload['fields']]);

        try {
            $this->schemaValidator->validateSchema($collection->schema());
        } catch (SchemaValidationException $e) {
            return $this->renderForm(
                $request,
                $collection,
                $e->errors(),
                isEdit: false,
                actionUrl: '/admin/collections/new',
            );
        }

        try {
            $created = $this->connection->transaction(function () use ($collection, $payload): Collection {
                $row = $this->collections->save($collection);
                $this->schemaChanges->apply(
                    $row->id(),
                    $this->schemaChangeHelperFields([]),
                    $this->schemaChangeHelperFields($payload['fields']),
                );
                return $row;
            });
        } catch (SafeException $e) {
            return $this->renderForm(
                $request,
                $collection,
                ['__schema' => [$e->publicMessage()]],
                isEdit: false,
                actionUrl: '/admin/collections/new',
            );
        }

        return new RedirectResponse(
            '/admin/collections/' . rawurlencode($created->slug()),
            Response::HTTP_SEE_OTHER,
        );
    }

    /**
     * @param array<string, string> $parameters
     */
    public function edit(Request $request, array $parameters = []): Response
    {
        $slug = (string) ($parameters['slug'] ?? '');
        $collection = $this->collections->findBySlug($slug);
        if ($collection === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        return $this->renderForm(
            $request,
            $collection,
            [],
            isEdit: true,
            actionUrl: '/admin/collections/' . rawurlencode($slug) . '/edit',
            cancelUrl: '/admin/collections/' . rawurlencode($slug),
        );
    }

    /**
     * @param array<string, string> $parameters
     */
    public function update(Request $request, array $parameters = []): Response
    {
        $slug = (string) ($parameters['slug'] ?? '');
        $collection = $this->collections->findBySlug($slug);
        if ($collection === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $payload = $this->extractPayload($request);

        if ($this->wantsRebuild($request)) {
            return $this->renderForm(
                $request,
                $collection
                    ->withSchema(['fields' => $this->rebuiltFieldList($payload['fields'], $request)])
                    ->withSlug($payload['slug'])
                    ->withName($payload['name']),
                [],
                isEdit: true,
                actionUrl: '/admin/collections/' . rawurlencode($slug) . '/edit',
                cancelUrl: '/admin/collections/' . rawurlencode($slug),
            );
        }

        $previousFields = $this->schemaChangeHelperFields($collection->fields());
        $updated = $collection
            ->withSchema(['fields' => $payload['fields']])
            ->withSlug($payload['slug'])
            ->withName($payload['name']);

        try {
            $this->schemaValidator->validateSchema($updated->schema());
        } catch (SchemaValidationException $e) {
            return $this->renderForm(
                $request,
                $updated,
                $e->errors(),
                isEdit: true,
                actionUrl: '/admin/collections/' . rawurlencode($slug) . '/edit',
                cancelUrl: '/admin/collections/' . rawurlencode($slug),
            );
        }

        try {
            $saved = $this->connection->transaction(function () use ($updated, $previousFields, $payload): Collection {
                $row = $this->collections->save($updated);
                $this->schemaChanges->apply(
                    $row->id(),
                    $previousFields,
                    $this->schemaChangeHelperFields($payload['fields']),
                );
                return $row;
            });
        } catch (SafeException $e) {
            return $this->renderForm(
                $request,
                $updated,
                ['__schema' => [$e->publicMessage()]],
                isEdit: true,
                actionUrl: '/admin/collections/' . rawurlencode($slug) . '/edit',
                cancelUrl: '/admin/collections/' . rawurlencode($slug),
            );
        }

        return new RedirectResponse(
            '/admin/collections/' . rawurlencode($saved->slug()),
            Response::HTTP_SEE_OTHER,
        );
    }

    /**
     * @param array<string, string> $parameters
     */
    public function destroy(Request $request, array $parameters = []): Response
    {
        $slug = (string) ($parameters['slug'] ?? '');
        $collection = $this->collections->findBySlug($slug);
        if ($collection === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $this->connection->transaction(function () use ($collection): void {
            $this->connection->execute(
                'DELETE FROM entry_values WHERE entry_id IN (SELECT id FROM entries WHERE collection_id = :id)',
                ['id' => $collection->id()],
            );
            $this->connection->execute(
                'DELETE FROM entries WHERE collection_id = :id',
                ['id' => $collection->id()],
            );
            $this->connection->execute(
                'DELETE FROM schema_change_log WHERE collection_id = :id',
                ['id' => $collection->id()],
            );
            $this->collections->delete($collection->id());
        });

        return new RedirectResponse('/admin/collections', Response::HTTP_SEE_OTHER);
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function renderForm(
        Request $request,
        Collection $collection,
        array $errors,
        bool $isEdit,
        string $actionUrl,
        ?string $cancelUrl = null,
    ): Response {
        $user = $request->attributes->get('user');

        return $this->html($this->renderer->render('admin/collections/form', [
            'user_name' => $user instanceof User ? $user->name : '',
            'fields' => $this->displayFields($collection->fields()),
            'field_types' => $this->fieldTypes,
            'errors' => $errors,
            'is_edit' => $isEdit,
            'action_url' => $actionUrl,
            'cancel_url' => $cancelUrl ?? '/admin/collections',
            'form_slug' => $collection->slug(),
            'form_name' => $collection->name(),
            'form_title' => $isEdit ? sprintf('Edit collection %s', $collection->slug()) : 'New collection',
        ]));
    }

    /**
     * Normalizes each field through its type so the form renders consistent
     * defaults; unknown types fall through unchanged.
     *
     * @param list<array<string, mixed>> $fields
     * @return list<array<string, mixed>>
     */
    private function displayFields(array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $type = (string) ($field['type'] ?? '');
            if ($type !== '' && $this->fieldTypes->has($type)) {
                $out[] = $this->fieldTypes->get($type)->normalize($field);
                continue;
            }
            $out[] = $field;
        }
        return $out;
    }

    /**
     * @return array{slug: string, name: string, fields: list<array<string, mixed>>}
     */
    private function extractPayload(Request $request): array
    {
        $slug = (string) $request->request->get('slug', '');
        $name = (string) $request->request->get('name', '');
        $fields = $this->coerceFields($request->request->all('fields'));

        return [
            'slug' => trim($slug),
            'name' => trim($name),
            'fields' => $fields,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function coerceFields(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $field) {
            if (is_array($field)) {
                $out[] = $field;
            }
        }
        return $out;
    }

    private function wantsRebuild(Request $request): bool
    {
        if ($request->request->has('_add_field')) {
            return true;
        }
        foreach ($request->request->keys() as $key) {
            if (is_string($key) && str_starts_with($key, '_remove_field[')) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<array<string, mixed>> $submittedFields
     * @return list<array<string, mixed>>
     */
    private function rebuiltFieldList(array $submittedFields, Request $request): array
    {
        $removeIndexes = $this->removeIndexes($request);
        $kept = [];
        foreach ($submittedFields as $index => $field) {
            if (isset($removeIndexes[$index])) {
                continue;
            }
            $kept[] = $field;
        }
        if ($request->request->has('_add_field')) {
            $kept[] = $this->emptyField();
        }
        return $kept;
    }

    /**
     * @return array<int, true>
     */
    private function removeIndexes(Request $request): array
    {
        $remove = [];
        foreach ($request->request->keys() as $key) {
            if (!is_string($key)) {
                continue;
            }
            if (preg_match('/^_remove_field\[(\d+)\]$/', $key, $matches) === 1) {
                $remove[(int) $matches[1]] = true;
            }
        }
        return $remove;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyField(): array
    {
        return ['key' => '', 'type' => 'text', 'label' => '', 'required' => false];
    }

    /**
     * Coerces the supplied fields into the shape {@see SchemaChangeHelper}
     * expects (each row carrying only key/type/label). Extra fields like
     * `required`/`default` are ignored by the helper but kept harmless in
     * storage.
     *
     * @param list<array<string, mixed>> $fields
     * @return list<array<string, mixed>>
     */
    private function schemaChangeHelperFields(array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $key = (string) ($field['key'] ?? '');
            $type = (string) ($field['type'] ?? '');
            if ($key === '' || $type === '') {
                continue;
            }
            $label = (string) ($field['label'] ?? $key);
            $out[] = ['key' => $key, 'type' => $type, 'label' => $label];
        }
        return $out;
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
