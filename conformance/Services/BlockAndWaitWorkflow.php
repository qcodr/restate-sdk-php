<?php

declare(strict_types=1);

namespace Restate\Conformance;

use Qcodr\Restate\Sdk\Context\SharedWorkflowContext;
use Qcodr\Restate\Sdk\Context\WorkflowContext;
use Qcodr\Restate\Sdk\Error\TerminalException;
use Qcodr\Restate\Sdk\Service\Attribute\Handler;
use Qcodr\Restate\Sdk\Service\Attribute\Shared;
use Qcodr\Restate\Sdk\Service\Attribute\Workflow;

/**
 * Workflow that blocks on a durable promise until an interaction handler unblocks it.
 *
 * Mirrors the cross-SDK conformance `BlockAndWaitWorkflow`: `run` persists its input
 * and suspends on `my-promise`, `unblock` resolves that promise, and `getState`
 * reads back the persisted input.
 */
#[Workflow(name: 'BlockAndWaitWorkflow')]
final class BlockAndWaitWorkflow
{
    private const MY_PROMISE = 'my-promise';
    private const MY_STATE = 'my-state';

    #[Handler]
    public function run(WorkflowContext $ctx, string $input): string
    {
        $ctx->set(self::MY_STATE, $input);

        $promise = $ctx->promise(self::MY_PROMISE);
        $promise = \is_string($promise) ? $promise : '';

        if ($ctx->peekPromise(self::MY_PROMISE) === null) {
            throw new TerminalException('Durable promise should be completed');
        }

        return $promise;
    }

    #[Shared]
    public function unblock(SharedWorkflowContext $ctx, string $output): void
    {
        $ctx->resolvePromise(self::MY_PROMISE, $output);
    }

    #[Shared]
    public function getState(SharedWorkflowContext $ctx): ?string
    {
        $state = $ctx->get(self::MY_STATE);

        return \is_string($state) ? $state : null;
    }
}
