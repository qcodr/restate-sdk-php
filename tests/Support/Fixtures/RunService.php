<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Support\Fixtures;

use Restate\Sdk\Context\Context;
use Restate\Sdk\Service\Attribute\Handler;
use Restate\Sdk\Service\Attribute\Service;

#[Service]
final class RunService
{
    /** Counts how many times the side effect closure actually executed. */
    private int $runs = 0;

    #[Handler]
    public function process(Context $ctx): string
    {
        return $ctx->run('step', function (): string {
            $this->runs++;

            return 'effect-result';
        });
    }

    public function runs(): int
    {
        return $this->runs;
    }
}
