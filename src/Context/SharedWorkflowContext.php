<?php

declare(strict_types=1);

namespace Restate\Sdk\Context;

/**
 * The context for shared (interaction) workflow handlers: read access to workflow
 * state plus durable promise operations, so they can resolve the promises the
 * `run` handler is awaiting. State writes are not available here.
 */
interface SharedWorkflowContext extends SharedObjectContext, DurablePromiseOperations
{
}
