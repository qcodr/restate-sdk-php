<?php

declare(strict_types=1);

namespace Restate\Sdk\Service\Attribute;

use Attribute;

/**
 * Marks a public method as a shared (read-only, concurrent) handler on a Virtual
 * Object or Workflow. Shared handlers may read state but not write it, and receive
 * a {@see \Restate\Sdk\Context\SharedObjectContext} /
 * {@see \Restate\Sdk\Context\SharedWorkflowContext}.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Shared
{
    public function __construct(public readonly ?string $name = null)
    {
    }
}
