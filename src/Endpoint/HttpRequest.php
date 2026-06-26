<?php

declare(strict_types=1);

namespace Restate\Sdk\Endpoint;

/**
 * A transport-agnostic view of an inbound HTTP request, so the
 * {@see RequestProcessor} core never depends on a specific server (Swoole, PSR-7, …).
 */
final class HttpRequest
{
    /**
     * @param array<string, string> $headers lower-cased header names
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[\strtolower($name)] ?? null;
    }
}
