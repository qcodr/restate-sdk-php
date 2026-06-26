<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Support\Fixtures;

use Restate\Sdk\Context\Context;
use Restate\Sdk\Service\Attribute\Handler;
use Restate\Sdk\Service\Attribute\Service;

#[Service]
final class Greeter
{
    #[Handler]
    public function greet(Context $ctx, string $name): string
    {
        return "Greetings {$name}";
    }
}
