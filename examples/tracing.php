<?php

declare(strict_types=1);

namespace Restate\Examples;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\Context\Context as OtelContext;
use Psr\Log\AbstractLogger;
use Restate\Sdk\Context\Context;
use Restate\Sdk\Context\TraceContext;
use Restate\Sdk\Endpoint\Endpoint;
use Restate\Sdk\Server\SwooleServer;
use Restate\Sdk\Service\Attribute\Handler;
use Restate\Sdk\Service\Attribute\Service;
use Stringable;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Observability: replay-aware logging + bridging the incoming trace to OpenTelemetry.
 *
 * ## Logging
 * `ctx->logger()` returns a PSR-3 logger that drops records while the invocation is
 * replaying. Because a handler re-runs from the top on every slice, "Before sleep"
 * would otherwise be logged again on the replay that follows the durable timer; the
 * replay-aware logger emits each line exactly once (during processing).
 *
 * ## Tracing — who propagates what
 * - **Across the service graph** (this invocation -> the services it calls/sends to):
 *   the **Restate runtime** owns propagation. It stamps `traceparent` on the request
 *   it sends the SDK, and links child invocations itself. You do NOT (and should not)
 *   manually forward `traceparent` on `ctx->serviceCall(...)` headers — that is the
 *   runtime's job, and double-injecting would fork the trace.
 * - **Inside one handler** (spans around your own DB / HTTP / compute work): that is
 *   yours. `ctx->traceContext()` exposes the inbound W3C context so your spans nest
 *   under the incoming trace. The SDK stays dependency-free and emits no spans itself.
 *
 * The {@see withIncomingTraceParent()} helper below is the canonical bridge into the
 * OpenTelemetry PHP SDK (install `open-telemetry/sdk` — see composer `suggest`).
 *
 * Run:   php examples/tracing.php
 * Try:   curl localhost:8080/TracingGreeter/greet -H 'content-type: application/json' -d '"world"'
 */
#[Service]
final class TracingGreeter
{
    #[Handler]
    public function greet(Context $ctx, string $name): string
    {
        $ctx->logger()->info('Before sleep');

        // Start an in-handler span nested under the runtime-provided trace. If the
        // OpenTelemetry SDK is not installed this is a no-op, so the example still runs.
        $parent = withIncomingTraceParent($ctx->traceContext());
        $span = $parent === null
            ? null
            : Globals::tracerProvider()->getTracer('restate-php-example')
                ->spanBuilder('TracingGreeter.greet')->setParent($parent)->startSpan();

        try {
            $ctx->sleep(1.0); // suspends, then the handler replays past this point
            $ctx->logger()->info('After sleep');

            return "Greetings {$name}";
        } finally {
            $span?->end();
        }
    }
}

/**
 * Bridges Restate's inbound {@see TraceContext} into an OpenTelemetry parent context.
 *
 * Returns null when there is no inbound trace or the OpenTelemetry SDK is absent, so
 * callers can treat tracing as optional.
 */
function withIncomingTraceParent(?TraceContext $trace): ?OtelContext
{
    if ($trace === null || !\class_exists(SpanContext::class)) {
        return null;
    }

    $spanContext = SpanContext::createFromRemoteParent(
        $trace->traceId,
        $trace->spanId(),
        $trace->isSampled() ? TraceFlags::SAMPLED : TraceFlags::DEFAULT,
    );

    return OtelContext::getCurrent()->withContextValue(Span::wrap($spanContext));
}

$logger = new class () extends AbstractLogger {
    /**
     * @param mixed                $level
     * @param array<string, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        \fwrite(\STDERR, \sprintf("[%s] %s\n", \strtoupper((string) $level), $message));
    }
};

$endpoint = Endpoint::builder()->bind(new TracingGreeter())->build();
(new SwooleServer($endpoint, logger: $logger))->listen('0.0.0.0', (int) (\getenv('PORT') ?: 9080));
