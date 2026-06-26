<?php

declare(strict_types=1);

namespace Restate\Examples;

use Qcodr\Restate\Sdk\Context\Context;
use Qcodr\Restate\Sdk\Endpoint\Endpoint;
use Qcodr\Restate\Sdk\Service\Attribute\Handler;
use Qcodr\Restate\Sdk\Service\Attribute\Service;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Fan-out / fan-in: start several durable futures concurrently and process each
 * result as it completes, using {@see Context::timer()} (non-blocking timers) and
 * {@see Context::select()} (await the first to finish).
 *
 * Run:   php bin/restate-serve examples/fan_out.php
 * Try:   curl localhost:8080/FanOut/fanOut
 *        -> "Completed in order: fast, medium, slow"
 */
#[Service]
final class FanOut
{
    #[Handler]
    public function fanOut(Context $ctx): string
    {
        $labels = ['fast', 'medium', 'slow'];

        // Fan out: one durable timer per task, keyed by original index.
        $pending = [
            0 => $ctx->timer(1.0),
            1 => $ctx->timer(2.0),
            2 => $ctx->timer(3.0),
        ];

        // Fan in: drain them in completion order.
        $completionOrder = [];
        while ($pending !== []) {
            $keys = \array_keys($pending);
            [$position] = $ctx->select(...\array_values($pending));
            $originalIndex = $keys[$position];

            $completionOrder[] = $labels[$originalIndex];
            unset($pending[$originalIndex]);
        }

        return 'Completed in order: ' . \implode(', ', $completionOrder);
    }
}

return Endpoint::builder()->bind(new FanOut())->build();
