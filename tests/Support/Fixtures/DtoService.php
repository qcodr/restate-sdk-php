<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Support\Fixtures;

use Qcodr\Restate\Sdk\Context\Context;
use Qcodr\Restate\Sdk\Service\Attribute\Handler;
use Qcodr\Restate\Sdk\Service\Attribute\Service;

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
