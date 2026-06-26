<?php

declare(strict_types=1);

/**
 * Minimal single-service endpoint for benchmarking, with a tunable Swoole worker
 * count (WORKER_NUM env, 0 = Swoole default of max(2, cpu_num)). Used by the
 * worker_num sweep in docs/BENCHMARKS.md to isolate the PHP endpoint's contribution
 * from the Restate runtime.
 *
 *   WORKER_NUM=4 PORT=9080 php benchmarks/e2e/bench-endpoint.php
 */

namespace Qcodr\Restate\Sdk\Benchmarks;

use Qcodr\Restate\Sdk\Context\Context;
use Qcodr\Restate\Sdk\Endpoint\Endpoint;
use Qcodr\Restate\Sdk\Server\SwooleServer;
use Qcodr\Restate\Sdk\Service\Attribute\Handler;
use Qcodr\Restate\Sdk\Service\Attribute\Service;

require __DIR__ . '/../../vendor/autoload.php';

#[Service]
final class BenchGreeter
{
    #[Handler]
    public function greet(Context $ctx, string $name): string
    {
        return "Greetings {$name}";
    }
}

$endpoint = Endpoint::builder()->bind(new BenchGreeter())->build();
$workers = (int) (\getenv('WORKER_NUM') ?: 0);

\fwrite(\STDERR, "bench endpoint: worker_num={$workers}\n");
(new SwooleServer($endpoint))->listen('0.0.0.0', (int) (\getenv('PORT') ?: 9080), $workers);
