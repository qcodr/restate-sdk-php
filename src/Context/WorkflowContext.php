<?php

declare(strict_types=1);

namespace Restate\Sdk\Context;

/**
 * The context for the workflow `run` handler: full object state read/write plus
 * durable promise operations. The run handler executes exactly once per workflow
 * key.
 */
interface WorkflowContext extends ObjectContext, DurablePromiseOperations
{
}
