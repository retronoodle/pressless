<?php

declare(strict_types=1);

namespace Pressless\Content\FieldType;

use Pressless\Exception\SafeException;

/**
 * Raised when a field-type key is requested that has not been registered.
 */
final class UnknownFieldTypeException extends SafeException
{
    public static function forKey(string $key): self
    {
        return new self(sprintf('Unknown field type "%s".', $key), ['key' => $key]);
    }
}
