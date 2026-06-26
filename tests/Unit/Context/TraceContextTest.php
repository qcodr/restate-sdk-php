<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Unit\Context;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Restate\Sdk\Context\TraceContext;

/**
 * Verifies W3C trace-context parsing from request headers: a well-formed
 * `traceparent` yields populated fields and round-trips through
 * {@see TraceContext::toTraceparent()}, while absent or malformed input yields null.
 */
final class TraceContextTest extends TestCase
{
    private const VALID = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

    public function testParsesAValidTraceparent(): void
    {
        $trace = TraceContext::fromHeaders([
            'traceparent' => self::VALID,
            'tracestate' => 'rojo=00f067aa0ba902b7',
        ]);

        self::assertNotNull($trace);
        self::assertSame('00', $trace->version);
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $trace->traceId);
        self::assertSame('00f067aa0ba902b7', $trace->parentId);
        self::assertSame('00f067aa0ba902b7', $trace->spanId());
        self::assertSame(1, $trace->traceFlags);
        self::assertTrue($trace->isSampled());
        self::assertSame(self::VALID, $trace->traceparent);
        self::assertSame('rojo=00f067aa0ba902b7', $trace->traceState);
    }

    public function testHeaderNameMatchingIsCaseInsensitiveAndTraceStateOptional(): void
    {
        $trace = TraceContext::fromHeaders(['TraceParent' => self::VALID]);

        self::assertNotNull($trace);
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $trace->traceId);
        self::assertNull($trace->traceState);
    }

    public function testReturnsNullWhenTraceparentAbsent(): void
    {
        self::assertNull(TraceContext::fromHeaders([]));
        self::assertNull(TraceContext::fromHeaders(['content-type' => 'application/json']));
    }

    #[DataProvider('malformedTraceparents')]
    public function testReturnsNullForMalformedTraceparent(string $traceparent): void
    {
        self::assertNull(TraceContext::fromHeaders(['traceparent' => $traceparent]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedTraceparents(): iterable
    {
        yield 'empty' => [''];
        yield 'too few parts' => ['00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7'];
        yield 'short trace id' => ['00-abc-00f067aa0ba902b7-01'];
        yield 'non-hex digit' => ['00-zzf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'];
        yield 'all-zero trace id' => ['00-00000000000000000000000000000000-00f067aa0ba902b7-01'];
        yield 'all-zero parent id' => ['00-4bf92f3577b34da6a3ce929d0e0e4736-0000000000000000-01'];
        yield 'forbidden ff version' => ['ff-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'];
        yield 'uppercase hex' => ['00-4BF92F3577B34DA6A3CE929D0E0E4736-00f067aa0ba902b7-01'];
    }

    public function testRoundTripsToTraceparent(): void
    {
        $trace = TraceContext::fromHeaders(['traceparent' => self::VALID]);

        self::assertNotNull($trace);
        self::assertSame(self::VALID, $trace->toTraceparent());
    }
}
