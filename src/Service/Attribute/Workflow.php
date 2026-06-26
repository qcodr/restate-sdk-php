<?php

declare(strict_types=1);

namespace Restate\Sdk\Service\Attribute;

use Attribute;

/**
 * Marks a class as a Restate Workflow: a virtual object whose `run` handler
 * executes exactly once per key, alongside concurrent {@see Shared} handlers that
 * interact with it (e.g. resolving durable promises). The run handler receives a
 * {@see \Restate\Sdk\Context\WorkflowContext}.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Workflow
{
    public function __construct(public readonly ?string $name = null)
    {
    }
}
