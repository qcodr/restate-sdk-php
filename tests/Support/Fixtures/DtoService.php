<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Support\Fixtures;

use Restate\Sdk\Context\Context;
use Restate\Sdk\Service\Attribute\Handler;
use Restate\Sdk\Service\Attribute\Service;

/**
 * Fixture service whose handler takes and returns a typed DTO, so the discovery
 * manifest carries object-shaped `jsonSchema` descriptors on input and output.
 */
#[Service]
final class DtoService
{
    #[Handler]
    public function process(Context $ctx, SampleDto $input): SampleDto
    {
        return $input;
    }
}
