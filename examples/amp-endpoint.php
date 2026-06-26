<?php

declare(strict_types=1);

namespace Restate\Examples;

use Qcodr\Restate\Sdk\Endpoint\Endpoint;
use Qcodr\Restate\Sdk\Endpoint\ProtocolMode;
use Qcodr\Restate\Sdk\Server\AmpStreamingServer;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Serves every example service over true bidirectional HTTP/2 streaming using the
 * amphp host ({@see AmpStreamingServer}) instead of Swoole. The endpoint opts into
 * {@see ProtocolMode::BidiStream}; only this host advertises BIDI_STREAM (the Swoole /
 * PSR-15 / Lambda hosts cap it back to REQUEST_RESPONSE).
 *
 * Run directly: php examples/amp-endpoint.php
 *
 * This mirrors examples/endpoint.php but requires amphp/http-server (a composer
 * `suggest`) and NO ext-swoole.
 */
$standalone = ['endpoint.php', 'amp-endpoint.php', 'tracing.php'];
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
    ->protocolMode(ProtocolMode::BidiStream)
    ->build();

(new AmpStreamingServer($endpoint))->listen('0.0.0.0', (int) (\getenv('PORT') ?: 9080));
