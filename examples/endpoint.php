<?php

declare(strict_types=1);

namespace Restate\Examples;

use Qcodr\Restate\Sdk\Endpoint\Endpoint;
use Qcodr\Restate\Sdk\Server\SwooleServer;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Serves every example service on one endpoint (port 9080). Each example file
 * defines its class and returns its own single-service endpoint; requiring them
 * here just loads the class definitions so they can be bound together.
 *
 * Run directly:   php examples/endpoint.php
 * Or per example: php bin/restate-serve examples/counter.php
 */
// tracing.php serves itself (it injects a logger), so it is excluded here.
$standalone = ['endpoint.php', 'tracing.php'];
foreach (\glob(__DIR__ . '/*.php') ?: [] as $file) {
    if (!\in_array(\basename($file), $standalone, true)) {
        require_once $file;
    }
}

$endpoint = Endpoint::builder()
    ->bind(new Greeter())
    ->bind(new Counter())
    ->bind(new RunExample())
    ->bind(new FailureExample())
    ->bind(new FanOut())
    ->bind(new CatalogService())
    ->bind(new PeriodicTask())
    ->bind(new MyService())
    ->bind(new MyVirtualObject())
    ->bind(new MyWorkflow())
    ->build();

(new SwooleServer($endpoint))->listen('0.0.0.0', (int) (\getenv('PORT') ?: 9080));
