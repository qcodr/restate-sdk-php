<?php

declare(strict_types=1);

namespace Restate\Sdk\Service\Attribute;

use Attribute;

/**
 * Marks a public method as an invokable handler.
 *
 * On a Virtual Object this is an exclusive (single-writer) handler; on a Workflow
 * it is the `run` entrypoint; on a Service it is a plain handler.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Handler
{
    public function __construct(public readonly ?string $name = null)
    {
    }
}
