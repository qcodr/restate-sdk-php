<?php

declare(strict_types=1);

/**
 * Entrypoint for the cross-SDK conformance test-services container, BIDIRECTIONAL variant.
 *
 * This mirrors {@see main.php} exactly — same service catalog, same SERVICES/PORT contract —
 * but serves over true bidirectional HTTP/2 (h2c) streaming with {@see AmpStreamingServer}
 * instead of request/response Swoole. The endpoint opts into {@see ProtocolMode::BidiStream},
 * so discovery advertises BIDI_STREAM and the Restate runtime opens a persistent invocation
 * stream. This is the host that exercises the streaming driver against a real runtime, and the
 * one configuration where cancellation / kill of a *suspended* invocation is delivered promptly
 * (the runtime wakes the open stream rather than re-invoking), which the request/response
 * transport cannot do.
 *
 * amphp runs a single event-loop process, so the deliberately in-memory (non-durable) counters
 * in Failing / NonDeterministic / TestUtilsService are naturally shared across invocations
 * within the process, exactly as the contract requires (no worker pool to pin).
 */

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Qcodr\Restate\Sdk\Endpoint\Endpoint;
use Qcodr\Restate\Sdk\Endpoint\ProtocolMode;
use Qcodr\Restate\Sdk\Server\AmpStreamingServer;
use Restate\Conformance\AwakeableHolder;
use Restate\Conformance\BlockAndWaitWorkflow;
use Restate\Conformance\CancelTestBlockingService;
use Restate\Conformance\CancelTestRunner;
use Restate\Conformance\Counter;
use Restate\Conformance\Failing;
use Restate\Conformance\KillTestRunner;
use Restate\Conformance\KillTestSingleton;
use Restate\Conformance\ListObject;
use Restate\Conformance\MapObject;
use Restate\Conformance\NonDeterministic;
use Restate\Conformance\Proxy;
use Restate\Conformance\TestUtilsService;
use Restate\Conformance\VirtualObjectCommandInterpreter;

require __DIR__ . '/../vendor/autoload.php';

foreach (\glob(__DIR__ . '/Services/*.php') ?: [] as $service) {
    require_once $service;
}

/** @var array<string, object> $catalog name => instance */
$catalog = [
    'Counter' => new Counter(),
    'Proxy' => new Proxy(),
    'MapObject' => new MapObject(),
    'ListObject' => new ListObject(),
    'AwakeableHolder' => new AwakeableHolder(),
    'BlockAndWaitWorkflow' => new BlockAndWaitWorkflow(),
    'CancelTestRunner' => new CancelTestRunner(),
    'CancelTestBlockingService' => new CancelTestBlockingService(),
    'Failing' => new Failing(),
    'KillTestRunner' => new KillTestRunner(),
    'KillTestSingleton' => new KillTestSingleton(),
    'NonDeterministic' => new NonDeterministic(),
    'TestUtilsService' => new TestUtilsService(),
    'VirtualObjectCommandInterpreter' => new VirtualObjectCommandInterpreter(),
];

$selection = \getenv('SERVICES') ?: '*';
$builder = Endpoint::builder()->protocolMode(ProtocolMode::BidiStream);
foreach ($catalog as $name => $instance) {
    if ($selection === '*' || \str_contains($selection, $name)) {
        $builder->bind($instance);
    }
}

// A real STDERR logger so a streaming-driver error surfaces in the container logs (and the
// conformance suite's captured service log) instead of being swallowed by a NullLogger.
// Filters amphp's chatty notice/info so only warnings and errors show.
$logger = new class () extends AbstractLogger {
    private const VISIBLE = [
        LogLevel::WARNING,
        LogLevel::ERROR,
        LogLevel::CRITICAL,
        LogLevel::ALERT,
        LogLevel::EMERGENCY,
    ];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (!\in_array($level, self::VISIBLE, true)) {
            return;
        }
        \fwrite(\STDERR, '[' . $level . '] ' . $message . "\n");
        $exception = $context['exception'] ?? null;
        if ($exception instanceof Throwable) {
            \fwrite(\STDERR, $exception . "\n");
        }
    }
};

$port = (int) (\getenv('PORT') ?: 9080);
(new AmpStreamingServer($builder->build(), logger: $logger))->listen('0.0.0.0', $port);
