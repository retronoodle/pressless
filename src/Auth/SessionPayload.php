<?php

declare(strict_types=1);

namespace Stead\Auth;

/**
 * Encodes session data in PHP's native `php` serialize_handler format
 * (`key|serialized_value` repeated), so records written explicitly at login are
 * readable by PHP's own session decoder on the next request.
 */
final class SessionPayload
{
    /**
     * @param array<string, mixed> $data
     */
    public static function encode(array $data): string
    {
        $encoded = '';
        foreach ($data as $key => $value) {
            $encoded .= $key . '|' . serialize($value);
        }
        return $encoded;
    }
}
