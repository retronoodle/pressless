<?php

declare(strict_types=1);

namespace Stead\Mail;

/**
 * An outbound mail message.
 *
 * A plain value object — the transport turns it into a wire payload. Headers
 * are a string→string map; values must already be RFC 5322 line-folded by the
 * caller if that is desired (the transport does not fold for them).
 */
final class Message
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $subject,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }

    public function withHeader(string $name, string $value): self
    {
        $headers = $this->headers;
        $headers[$name] = $value;
        return new self($this->from, $this->to, $this->subject, $this->body, $headers);
    }
}
