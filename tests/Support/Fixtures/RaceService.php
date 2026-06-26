<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Support\Fixtures;

use Restate\Sdk\Context\Context;
use Restate\Sdk\Service\Attribute\Handler;
use Restate\Sdk\Service\Attribute\Service;

#[Service]
final class RaceService
{
    #[Handler]
    public function race(Context $ctx): string
    {
        $first = $ctx->serviceCallAsync('Backend', 'slow');
        $second = $ctx->serviceCallAsync('Backend', 'fast');

        [$index, $value] = $ctx->select($first, $second);
        $value = \is_string($value) ? $value : '';

        return "winner:{$index}:{$value}";
    }
}
