<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Service;

/**
 * The kind of a handler. Services leave this unset; Virtual Objects use
 * Exclusive/Shared; Workflows use Workflow for the run handler and Shared for
 * interaction handlers.
 */
enum HandlerType: string
{
    case Exclusive = 'EXCLUSIVE';
    case Shared = 'SHARED';
    case Workflow = 'WORKFLOW';

    public function isShared(): bool
    {
        return $this === self::Shared;
    }
}
