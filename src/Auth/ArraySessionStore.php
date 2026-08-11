<?php

declare(strict_types=1);

namespace Stead\Auth;

/**
 * An in-memory session store for tests and CLI contexts.
 *
 * Mirrors the semantics of {@see NativeSessionStore} — including identifier
 * regeneration — without touching PHP's session machinery or sending headers.
 */
final class ArraySessionStore implements SessionStore
{
    /** @var array<string, mixed> */
    private array $data = [];
    private string $id = '';
    private bool $started = false;

    /** @var list<string> */
    private array $destroyedIds = [];

    public function __construct(private readonly ?SessionRepository $sessions = null)
    {
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }
        if ($this->id === '') {
            $this->id = self::newId();
        }
        $this->started = true;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function regenerate(bool $deleteOld = true): void
    {
        $this->start();
        $previous = $this->id;
        $this->id = self::newId();

        if ($deleteOld && $previous !== '') {
            $this->destroyedIds[] = $previous;
            $this->sessions?->delete($previous);
        }
    }

    public function destroy(): void
    {
        if ($this->id !== '') {
            $this->destroyedIds[] = $this->id;
            $this->sessions?->delete($this->id);
        }
        $this->data = [];
        $this->started = false;
        $this->id = '';
    }

    public function save(): void
    {
        $this->started = false;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * Identifiers destroyed during this store's lifetime, for assertions.
     *
     * @return list<string>
     */
    public function destroyedIds(): array
    {
        return $this->destroyedIds;
    }

    private static function newId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
