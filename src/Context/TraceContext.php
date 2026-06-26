<?php

declare(strict_types=1);

namespace Restate\Sdk\Context;

/**
 * The W3C Trace Context (https://www.w3.org/TR/trace-context/) the Restate runtime
 * propagates on the `traceparent` / `tracestate` request headers.
 *
 * This is an inert value object: the SDK stays dependency-free and never emits spans
 * itself. To produce OpenTelemetry spans, bridge these fields into the OpenTelemetry
 * PHP SDK — build a remote `SpanContext` from {@see $traceId}, {@see $parentId} and
 * {@see $traceFlags}, or re-inject {@see toTraceparent()} through a text-map
 * propagator — then start child spans around your handler logic so they nest under
 * the incoming trace.
 */
final readonly class TraceContext
{
    /** The number of hex digits in a W3C trace id. */
    private const TRACE_ID_LENGTH = 32;

    /** The number of hex digits in a W3C parent (span) id. */
    private const PARENT_ID_LENGTH = 16;

    /** The number of hex digits in the version and trace-flags fields. */
    private const BYTE_LENGTH = 2;

    /**
     * @param string  $version     the two-hex-digit trace-context version (e.g. "00")
     * @param string  $traceId     the 32-hex-digit trace id
     * @param string  $parentId    the 16-hex-digit parent span id (the caller's span id)
     * @param int     $traceFlags  the 8-bit trace-flags field (bit 0 = sampled)
     * @param string  $traceparent the raw `traceparent` header value
     * @param ?string $traceState  the raw `tracestate` header value, if present
     */
    public function __construct(
        public string $version,
        public string $traceId,
        public string $parentId,
        public int $traceFlags,
        public string $traceparent,
        public ?string $traceState = null,
    ) {
    }

    /** The W3C "parent-id": the caller's span id. Alias for {@see $parentId}. */
    public function spanId(): string
    {
        return $this->parentId;
    }

    /** Whether the sampled flag (bit 0 of trace-flags) is set. */
    public function isSampled(): bool
    {
        return ($this->traceFlags & 0x01) === 0x01;
    }

    /**
     * Parses the W3C trace context from a request-header map. Header-name matching is
     * case-insensitive. Returns null when the `traceparent` header is absent or
     * malformed (so a missing/garbled trace never aborts the handler).
     *
     * @param array<string, string> $headers name => value request headers
     */
    public static function fromHeaders(array $headers): ?self
    {
        $traceparent = null;
        $traceState = null;
        foreach ($headers as $name => $value) {
            $lower = \strtolower($name);
            if ($lower === 'traceparent') {
                $traceparent = $value;
            } elseif ($lower === 'tracestate') {
                $traceState = $value;
            }
        }

        if ($traceparent === null) {
            return null;
        }

        return self::parse($traceparent, $traceState);
    }

    /** Re-serializes the parsed fields into a `traceparent` header value. */
    public function toTraceparent(): string
    {
        return \sprintf(
            '%s-%s-%s-%02x',
            $this->version,
            $this->traceId,
            $this->parentId,
            $this->traceFlags & 0xFF,
        );
    }

    /**
     * Parses `version "-" trace-id "-" parent-id "-" trace-flags`, rejecting anything
     * that violates the spec: wrong field lengths, non-lowercase-hex digits, the
     * reserved "ff" version, and the all-zero (invalid) trace-id / parent-id.
     */
    private static function parse(string $traceparent, ?string $traceState): ?self
    {
        $parts = \explode('-', $traceparent);
        if (\count($parts) !== 4) {
            return null;
        }
        [$version, $traceId, $parentId, $flags] = $parts;

        if (!self::isLowerHex($version, self::BYTE_LENGTH)
            || !self::isLowerHex($traceId, self::TRACE_ID_LENGTH)
            || !self::isLowerHex($parentId, self::PARENT_ID_LENGTH)
            || !self::isLowerHex($flags, self::BYTE_LENGTH)
        ) {
            return null;
        }
        if ($version === 'ff'
            || $traceId === \str_repeat('0', self::TRACE_ID_LENGTH)
            || $parentId === \str_repeat('0', self::PARENT_ID_LENGTH)
        ) {
            return null;
        }

        return new self(
            $version,
            $traceId,
            $parentId,
            (int) \hexdec($flags),
            $traceparent,
            $traceState,
        );
    }

    /** Whether $value is exactly $length lowercase hexadecimal digits. */
    private static function isLowerHex(string $value, int $length): bool
    {
        return \strlen($value) === $length
            && \ctype_xdigit($value)
            && \strtolower($value) === $value;
    }
}
