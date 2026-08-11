<?php

declare(strict_types=1);

namespace Stead\Exception;

use Stead\Config\Configuration;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class ExceptionHandler
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Configuration $config,
        private readonly bool $debug = false,
    ) {
    }

    public function report(\Throwable $exception): void
    {
        $context = [
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'environment' => $this->config->environment(),
        ];
        if ($exception instanceof SafeException) {
            $context['safe_context'] = $exception->context();
        }
        $this->logger->error('Unhandled exception: ' . $exception->getMessage(), $context);
    }

    public function render(\Throwable $exception, ?string $accept = null): Response
    {
        $status = 500;
        $publicMessage = 'An unexpected error occurred.';

        if ($exception instanceof SafeException) {
            $status = $exception->getCode() >= 400 && $exception->getCode() < 600
                ? $exception->getCode()
                : 500;
            $publicMessage = $exception->publicMessage();
        }

        $wantsJson = $accept !== null && str_contains($accept, 'application/json');

        if ($wantsJson) {
            return new JsonResponse(['error' => $publicMessage], $status);
        }

        if ($status === 405) {
            return new Response($publicMessage, $status);
        }

        if ($status >= 500) {
            $body = $this->debug
                ? sprintf("<pre>%s</pre>", htmlspecialchars((string) $exception, ENT_QUOTES))
                : $publicMessage;
        } else {
            $body = $publicMessage;
        }

        return new Response($body, $status);
    }
}
