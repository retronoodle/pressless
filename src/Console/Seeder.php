<?php

declare(strict_types=1);

namespace Stead\Console;

use Stead\Auth\PasswordHasher;
use Stead\Auth\User;
use Stead\Auth\UserRepository;
use Stead\Config\Configuration;
use Stead\Content\Collection;
use Stead\Content\Entry;
use Stead\Content\EntryRepository;
use Stead\Content\FieldType\BooleanFieldType;
use Stead\Content\FieldType\DateFieldType;
use Stead\Content\FieldType\FieldTypeRegistry;
use Stead\Content\FieldType\NumberFieldType;
use Stead\Content\FieldType\RichtextFieldType;
use Stead\Content\FieldType\TextFieldType;
use Stead\Content\RevisionRepository;
use Stead\Content\SlugGenerator;
use Stead\Database\Connection;
use Stead\Exception\SafeException;

/**
 * Idempotent sample seeding for evaluator environments.
 *
 * The seeder is safe to re-run: existing administrators and collections are
 * preserved, only missing records are created. The temporary administrator
 * password is reported to the caller so the serve command can show it without
 * committing credentials anywhere.
 */
final class Seeder
{
    /** Default administrator created on first seed. */
    private const ADMIN_EMAIL = 'admin@example.com';
    private const ADMIN_NAME = 'Site Administrator';
    private const ADMIN_PASSWORD_LENGTH = 16;

    /** Empty sample collections created on first seed. */
    private const SAMPLE_COLLECTIONS = [
        ['slug' => 'pages', 'name' => 'Pages'],
    ];

    /** Slug of the demo content collection seeded with three sample entries. */
    private const POSTS_COLLECTION_SLUG = 'posts';
    private const POSTS_COLLECTION_NAME = 'Posts';

    /** Field set for the demo `posts` collection. */
    private const POSTS_FIELDS = [
        ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'required' => true],
        ['key' => 'body', 'type' => 'richtext', 'label' => 'Body'],
        ['key' => 'published_at', 'type' => 'date', 'label' => 'Published at'],
    ];

    /** Deterministic sample entries for the `posts` collection. */
    private const POSTS_ENTRIES = [
        [
            'slug' => 'hello-world',
            'title' => 'Hello, world',
            'body' => "Welcome to Stead. This is the first post in the seeded "
                . "`posts` collection, so you can see the content engine at "
                . "work without configuring anything by hand.",
            'published_at' => '2025-01-01',
        ],
        [
            'slug' => 'why-a-typed-cms',
            'title' => 'Why a typed CMS',
            'body' => "Stead stores every field value in the typed column "
                . "that matches its kind, so the public site can sort, filter, "
                . "and render without branching on string drift.",
            'published_at' => '2025-01-08',
        ],
        [
            'slug' => 'field-types-in-plain-english',
            'title' => 'Field types, in plain English',
            'body' => "Eight field types ship out of the box: text, richtext, "
                . "number, boolean, date, select, media, and relation. Each one "
                . "owns its schema fragment, validation, persistence, and form.",
            'published_at' => '2025-01-15',
        ],
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly Configuration $config,
    ) {
    }

    /**
     * Runs the seed and reports what was created so the caller can surface the
     * temporary administrator credentials to the user.
     *
     * Pass `allowProduction = true` from an explicit override (e.g.
     * `bin/serve --allow-production-seed`) to seed in a production environment.
     *
     * @return array{admin_email: ?string, admin_password: ?string, collections_created: int, entries_created: int}
     */
    public function seed(bool $allowProduction = false): array
    {
        if (!$this->config->isDevelopment() && !$allowProduction && !$this->config->getBool('app.allow_seed', false)) {
            throw new SafeException(
                'Seeding is only allowed in development environments.',
                ['environment' => $this->config->environment()],
            );
        }

        $hasher = new PasswordHasher();
        $users = new UserRepository($this->connection, $hasher);

        $existing = $this->findUser(self::ADMIN_EMAIL);
        if ($existing instanceof User) {
            $adminEmail = null;
            $adminPassword = null;
        } else {
            $tempPassword = self::generatePassword();
            $users->create(self::ADMIN_EMAIL, self::ADMIN_NAME, $tempPassword, true, true);
            $adminEmail = self::ADMIN_EMAIL;
            $adminPassword = $tempPassword;
        }

        $created = 0;
        foreach (self::SAMPLE_COLLECTIONS as $collection) {
            if ($this->collectionExists($collection['slug'])) {
                continue;
            }
            $this->createCollection($collection['slug'], $collection['name']);
            $created++;
        }

        $entriesCreated = 0;
        if (!$this->collectionExists(self::POSTS_COLLECTION_SLUG)) {
            $this->createPostsCollection();
            $created++;
            $entriesCreated = $this->createPostsEntries();
        } else {
            $entriesCreated = $this->createPostsEntries();
        }

        return [
            'admin_email' => $adminEmail,
            'admin_password' => $adminPassword,
            'collections_created' => $created,
            'entries_created' => $entriesCreated,
        ];
    }

    private function findUser(string $email): ?User
    {
        $row = $this->connection->fetchOne(
            'SELECT id, email, name, password_hash, is_active, is_admin FROM users WHERE email = :email',
            ['email' => UserRepository::normalizeEmail($email)],
        );

        return $row === null ? null : User::fromRow($row);
    }

    private function collectionExists(string $slug): bool
    {
        $row = $this->connection->fetchOne(
            'SELECT id FROM collections WHERE slug = :slug',
            ['slug' => $slug],
        );
        return $row !== null;
    }

    private function createCollection(string $slug, string $name): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $schema = json_encode([
            'fields' => [
                ['key' => 'title', 'type' => 'text', 'required' => true],
                ['key' => 'body', 'type' => 'markdown'],
            ],
        ]);

        $this->connection->execute(
            'INSERT INTO collections (slug, name, schema_definition, created_at, updated_at)
             VALUES (:slug, :name, :schema_definition, :created_at, :updated_at)',
            [
                'slug' => $slug,
                'name' => $name,
                'schema_definition' => $schema,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }

    private function createPostsCollection(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $schema = json_encode(['fields' => self::POSTS_FIELDS]);

        $this->connection->execute(
            'INSERT INTO collections (slug, name, schema_definition, created_at, updated_at)
             VALUES (:slug, :name, :schema_definition, :created_at, :updated_at)',
            [
                'slug' => self::POSTS_COLLECTION_SLUG,
                'name' => self::POSTS_COLLECTION_NAME,
                'schema_definition' => $schema,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }

    /**
     * @return int number of entries actually inserted (already-existing entries are skipped)
     */
    private function createPostsEntries(): int
    {
        $row = $this->connection->fetchOne(
            'SELECT id FROM collections WHERE slug = :slug',
            ['slug' => self::POSTS_COLLECTION_SLUG],
        );
        if ($row === null) {
            return 0;
        }
        $collectionId = (int) $row['id'];

        $collection = new Collection(
            $collectionId,
            self::POSTS_COLLECTION_SLUG,
            self::POSTS_COLLECTION_NAME,
            ['fields' => self::POSTS_FIELDS],
        );
        $entries = $this->buildEntryRepository();

        $created = 0;
        foreach (self::POSTS_ENTRIES as $entry) {
            $slug = (string) $entry['slug'];
            if ($this->entryExists($collectionId, $slug)) {
                continue;
            }
            $this->createPostEntry($entries, $collection, $entry);
            $created++;
        }
        return $created;
    }

    /**
     * @param array{slug: string, title: string, body: string, published_at: string} $entry
     */
    private function createPostEntry(EntryRepository $entries, Collection $collection, array $entry): void
    {
        $saved = $entries->save(
            new Entry(0, $collection->id(), '', []),
            $collection,
            [
                'title' => $entry['title'],
                'body' => $entry['body'],
                'published_at' => $entry['published_at'],
            ],
        );
        // Save defaults to draft; explicitly publish so the seeded content
        // is reachable on the public site.
        $entries->publish($saved->id());
    }

    private function buildEntryRepository(): EntryRepository
    {
        $fieldTypes = new FieldTypeRegistry([
            new TextFieldType(),
            new RichtextFieldType(),
            new NumberFieldType(),
            new BooleanFieldType(),
            new DateFieldType(),
        ]);
        $revisions = new RevisionRepository($this->connection);
        return new EntryRepository(
            $this->connection,
            $fieldTypes,
            new SlugGenerator($this->connection),
            $revisions,
            $this->config,
        );
    }

    private function entryExists(int $collectionId, string $slug): bool
    {
        $row = $this->connection->fetchOne(
            'SELECT id FROM entries WHERE collection_id = :collection_id AND slug = :slug',
            ['collection_id' => $collectionId, 'slug' => $slug],
        );
        return $row !== null;
    }

    private static function generatePassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $length = self::ADMIN_PASSWORD_LENGTH;
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $password;
    }
}