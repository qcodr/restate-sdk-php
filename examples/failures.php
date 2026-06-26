<?php

declare(strict_types=1);

namespace Restate\Examples;

use Qcodr\Restate\Sdk\Context\Context;
use Qcodr\Restate\Sdk\Endpoint\Endpoint;
use Qcodr\Restate\Sdk\Error\TerminalException;
use Qcodr\Restate\Sdk\Service\Attribute\Handler;
use Qcodr\Restate\Sdk\Service\Attribute\Service;
use RuntimeException;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Error semantics: a {@see TerminalException} ends the invocation with a failure
 * that is NOT retried, while any other throwable is a transient error that Restate
 * retries (with backoff). Run a few times to observe both outcomes.
 *
 * Run:   php bin/restate-serve examples/failures.php
 * Try:   curl localhost:8080/FailureExample/doRun
 */
#[Service]
final class FailureExample
{
    #[Handler(name: 'doRun')]
    public function doRun(Context $ctx): void
    {
        $ctx->run('maybe_fail', static function (): void {
            if (\random_int(0, 3) === 0) {
                throw new TerminalException('Failed!!! (terminal — will not retry)');
            }

            throw new RuntimeException("I'm very bad, retry me");
        });
    }
}

return Endpoint::builder()->bind(new FailureExample())->build();
