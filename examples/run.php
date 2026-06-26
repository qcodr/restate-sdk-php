<?php

declare(strict_types=1);

namespace Restate\Examples;

use Qcodr\Restate\Sdk\Context\Context;
use Qcodr\Restate\Sdk\Endpoint\Endpoint;
use Qcodr\Restate\Sdk\Service\Attribute\Handler;
use Qcodr\Restate\Sdk\Service\Attribute\Service;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Durable side effects: `ctx->run()` executes a non-deterministic action (here an
 * HTTP request) exactly once and journals its result, so retries and replays reuse
 * the stored value instead of calling out again.
 *
 * Run:   php bin/restate-serve examples/run.php
 * Try:   curl localhost:8080/RunExample/doRun
 */
#[Service]
final class RunExample
{
    #[Handler]
    public function doRun(Context $ctx): mixed
    {
        return $ctx->run('get_ip', static function (): mixed {
            $body = \file_get_contents('https://httpbin.org/ip');

            return $body === false ? ['origin' => 'unknown'] : \json_decode($body, true);
        });
    }
}

return Endpoint::builder()->bind(new RunExample())->build();
