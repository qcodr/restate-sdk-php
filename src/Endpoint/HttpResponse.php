<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Endpoint;

/**
 * A transport-agnostic HTTP response produced by the {@see RequestProcessor}.
 */
final class HttpResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    /**
     * @param array<string, string> $headers
     */
    public static function of(int $status, string $body, array $headers = []): self
    {
        return new self($status, $headers, $body);
    }
}
