<?php

declare(strict_types=1);

namespace Restate\Sdk\Service;

/**
 * The kind of a Restate service, as advertised in the discovery manifest.
 */
enum ServiceType: string
{
    case Service = 'SERVICE';
    case VirtualObject = 'VIRTUAL_OBJECT';
    case Workflow = 'WORKFLOW';

    public function hasState(): bool
    {
        return $this !== self::Service;
    }

    public function hasKey(): bool
    {
        return $this !== self::Service;
    }
}
