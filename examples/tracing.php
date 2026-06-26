<?php

declare(strict_types=1);

namespace Restate\Examples;

use Psr\Log\AbstractLogger;
use Restate\Sdk\Context\Context;
use Restate\Sdk\Endpoint\Endpoint;
use Restate\Sdk\Server\SwooleServer;
use Restate\Sdk\Service\Attribute\Handler;
use Restate\Sdk\Service\Attribute\Service;
use Stringable;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Replay-aware logging — the PHP analogue of the Rust SDK's tracing filter.
 *
 * `ctx->logger()` returns a PSR-3 logger that drops records while the invocation is
 * replaying. Because a handler re-runs from the top on every slice, "Before sleep"
 * would otherwise be logged again on the replay that follows the durable timer;
 * the replay-aware logger emits each line exactly once (during processing).
 *
 * Unlike the other examples, this one serves itself so it can inject a real logger.
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
        $ctx->sleep(1.0); // suspends, then the handler replays past this point
        $ctx->logger()->info('After sleep');

        return "Greetings {$name}";
    }
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
