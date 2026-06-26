<?php

declare(strict_types=1);

namespace Restate\Examples;

use Qcodr\Restate\Sdk\Context\Context;
use Qcodr\Restate\Sdk\Endpoint\Endpoint;
use Qcodr\Restate\Sdk\Service\Attribute\Handler;
use Qcodr\Restate\Sdk\Service\Attribute\Service;

require __DIR__ . '/../vendor/autoload.php';

/**
 * The simplest service: a single stateless handler.
 *
 * Run:   php bin/restate-serve examples/greeter.php
 * Try:   curl localhost:8080/Greeter/greet -d '"world"'
 */
#[Service]
final class Greeter
{
    #[Handler]
    public function greet(Context $ctx, string $name): string
    {
        return "Greetings {$name}";
    }
}

return Endpoint::builder()->bind(new Greeter())->build();
