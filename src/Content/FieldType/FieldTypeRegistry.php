<?php

declare(strict_types=1);

namespace Stead\Content\FieldType;

/**
 * Single source of truth for which {@see FieldType} implementations are
 * available. Constructed explicitly with the list of registered types; there
 * is no service locator and no implicit discovery.
 */
final class FieldTypeRegistry
{
    /**
     * @var array<string, FieldType>
     */
    private array $byKey;

    /**
     * @param list<FieldType> $types
     */
    public function __construct(array $types)
    {
        $byKey = [];
        foreach ($types as $type) {
            $key = $type->key();
            if (isset($byKey[$key])) {
                throw new \Stead\Exception\SafeException(
                    sprintf('Field type "%s" is registered twice.', $key),
                    ['key' => $key],
                );
            }
            $byKey[$key] = $type;
        }
        $this->byKey = $byKey;
    }

    public function has(string $key): bool
    {
        return isset($this->byKey[$key]);
    }

    /**
     * @throws UnknownFieldTypeException when the key is not registered.
     */
    public function get(string $key): FieldType
    {
        if (!isset($this->byKey[$key])) {
            throw UnknownFieldTypeException::forKey($key);
        }
        return $this->byKey[$key];
    }

    /**
     * @return list<FieldType>
     */
    public function all(): array
    {
        return array_values($this->byKey);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->byKey);
    }
}
