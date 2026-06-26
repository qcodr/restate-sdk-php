<?php

declare(strict_types=1);

/**
 * Entrypoint for the cross-SDK conformance test-services container.
 *
 * The Restate `sdk-test-suite` runs this image, injecting:
 *   - PORT      the port to bind (default 9080)
 *   - SERVICES  a comma-separated subset of service names to register, or "*"
 *
 * It binds exactly the requested services (substring match, mirroring the Rust
 * test-services), and serves them on a SINGLE Swoole worker so the deliberately
 * in-memory (non-durable) counters in Failing / NonDeterministic / TestUtilsService
 * are shared across invocations within the process, as the contract requires.
 */

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
use Restate\Sdk\Endpoint\Endpoint;
use Restate\Sdk\Server\SwooleServer;

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
$builder = Endpoint::builder();
foreach ($catalog as $name => $instance) {
    if ($selection === '*' || \str_contains($selection, $name)) {
        $builder->bind($instance);
    }
}

$port = (int) (\getenv('PORT') ?: 9080);
(new SwooleServer($builder->build()))->listen('0.0.0.0', $port, workerNum: 1);
