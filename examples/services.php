<?php

declare(strict_types=1);

namespace Restate\Examples;

use Qcodr\Restate\Sdk\Context\Context;
use Qcodr\Restate\Sdk\Context\ObjectContext;
use Qcodr\Restate\Sdk\Context\SharedObjectContext;
use Qcodr\Restate\Sdk\Context\SharedWorkflowContext;
use Qcodr\Restate\Sdk\Context\WorkflowContext;
use Qcodr\Restate\Sdk\Endpoint\Endpoint;
use Qcodr\Restate\Sdk\Service\Attribute\Handler;
use Qcodr\Restate\Sdk\Service\Attribute\Service;
use Qcodr\Restate\Sdk\Service\Attribute\Shared;
use Qcodr\Restate\Sdk\Service\Attribute\VirtualObject;
use Qcodr\Restate\Sdk\Service\Attribute\Workflow;

require __DIR__ . '/../vendor/autoload.php';

/**
 * The canonical trio bound on a single endpoint: a Service, a Virtual Object, and a
 * Workflow.
 *
 * Run: php bin/restate-serve examples/services.php
 */
#[Service]
final class MyService
{
    #[Handler]
    public function myHandler(Context $ctx, string $greeting): string
    {
        return "{$greeting}!";
    }
}

#[VirtualObject]
final class MyVirtualObject
{
    #[Handler]
    public function myHandler(ObjectContext $ctx, string $greeting): string
    {
        return "Greetings {$greeting} {$ctx->key()}";
    }

    #[Shared]
    public function myConcurrentHandler(SharedObjectContext $ctx, string $greeting): string
    {
        return "Greetings {$greeting} {$ctx->key()}";
    }
}

#[Workflow]
final class MyWorkflow
{
    #[Handler]
    public function run(WorkflowContext $ctx, string $req): string
    {
        // Block until an interaction handler resolves the promise.
        $signal = $ctx->promise('signal');

        return "success: {$req} / {$signal}";
    }

    #[Shared]
    public function interactWithWorkflow(SharedWorkflowContext $ctx, string $token): void
    {
        $ctx->resolvePromise('signal', $token);
    }
}

return Endpoint::builder()
    ->bind(new MyService())
    ->bind(new MyVirtualObject())
    ->bind(new MyWorkflow())
    ->build();
