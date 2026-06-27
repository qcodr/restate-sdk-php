<?php

declare(strict_types=1);

/**
 * Minimal single-service endpoint for benchmarking the bidirectional-streaming HTTP/2
 * host ({@see AmpStreamingServer}) — the AMP counterpart of bench-endpoint.php (Swoole).
 * The two serve the identical BenchGreeter so the e2e harness (benchmarks/e2e/run.sh,
 * TRANSPORT=amp) can compare the default amphp transport against the Swoole one.
 *
 * The endpoint opts into {@see ProtocolMode::BidiStream} so the runtime keeps the
 * invocation stream open and the request rides the bidi driver, not a buffered response.
 *
 *   PORT=9080 php benchmarks/e2e/bench-endpoint-amp.php
 */

namespace Qcodr\Restate\Sdk\Benchmarks;

use Qcodr\Restate\Sdk\Context\Context;
use Qcodr\Restate\Sdk\Endpoint\Endpoint;
use Qcodr\Restate\Sdk\Endpoint\ProtocolMode;
use Qcodr\Restate\Sdk\Server\AmpStreamingServer;
use Qcodr\Restate\Sdk\Service\Attribute\Handler;
use Qcodr\Restate\Sdk\Service\Attribute\Service;

require __DIR__ . '/../../vendor/autoload.php';

// Same Restate service name ("BenchGreeter") as the Swoole bench so both transports
// answer the identical ingress path; a distinct PHP class avoids an FQCN clash under
// static analysis (both bench files share one namespace).
#[Service(name: 'BenchGreeter')]
final class BenchGreeterAmp
{
    #[Handler]
    public function greet(Context $ctx, string $name): string
    {
        return "Greetings {$name}";
    }
}

$endpoint = Endpoint::builder()
    ->bind(new BenchGreeterAmp())
    ->protocolMode(ProtocolMode::BidiStream)
    ->build();

// WORKER_NUM mirrors the Swoole bench: 0 = one worker per CPU, N = N workers, so the
// single-event-loop ceiling can be lifted to the runtime-bound plateau (see docs/BENCHMARKS.md).
$workers = (int) (\getenv('WORKER_NUM') ?: 1);
\fwrite(\STDERR, "bench endpoint: amphp bidi streaming, workers={$workers}\n");
(new AmpStreamingServer($endpoint))->listen('0.0.0.0', (int) (\getenv('PORT') ?: 9080), $workers);
