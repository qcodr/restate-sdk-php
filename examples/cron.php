<?php

declare(strict_types=1);

namespace Restate\Examples;

use Qcodr\Restate\Sdk\Context\ObjectContext;
use Qcodr\Restate\Sdk\Endpoint\Endpoint;
use Qcodr\Restate\Sdk\Service\Attribute\Handler;
use Qcodr\Restate\Sdk\Service\Attribute\VirtualObject;

require __DIR__ . '/../vendor/autoload.php';

/**
 * A periodic task that drives itself by scheduling delayed one-way calls to its own
 * `run` handler. A persisted `active` flag gates the loop, so `stop` cleanly ends it.
 *
 * Run:   php bin/restate-serve examples/cron.php
 * Start: curl localhost:8080/PeriodicTask/my-task/start
 * Stop:  curl localhost:8080/PeriodicTask/my-task/stop
 */
#[VirtualObject]
final class PeriodicTask
{
    private const ACTIVE = 'active';
    private const PERIOD_SECONDS = 10.0;

    #[Handler]
    public function start(ObjectContext $ctx): void
    {
        if ($ctx->get(self::ACTIVE) === true) {
            return; // already running
        }

        $this->scheduleNext($ctx);
        $ctx->set(self::ACTIVE, true);
    }

    #[Handler]
    public function stop(ObjectContext $ctx): void
    {
        $ctx->clear(self::ACTIVE);
    }

    #[Handler]
    public function run(ObjectContext $ctx): void
    {
        if ($ctx->get(self::ACTIVE) !== true) {
            return; // stopped
        }

        // --- periodic business logic goes here ---

        $this->scheduleNext($ctx);
    }

    private function scheduleNext(ObjectContext $ctx): void
    {
        // Schedule the next run by sending a delayed one-way call to ourselves.
        $ctx->objectSend('PeriodicTask', $ctx->key(), 'run', null, self::PERIOD_SECONDS);
    }
}

return Endpoint::builder()->bind(new PeriodicTask())->build();
