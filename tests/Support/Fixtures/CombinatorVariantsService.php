<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Support\Fixtures;

use Qcodr\Restate\Sdk\Context\Context;
use Qcodr\Restate\Sdk\Service\Attribute\Handler;
use Qcodr\Restate\Sdk\Service\Attribute\Service;

/**
 * Exercises the {@see Context::awaitAny} (Promise.any) and
 * {@see Context::awaitAllSucceeded} (Promise.all) combinator variants over two
 * concurrent calls whose results land on completion ids 2 and 4.
 */
#[Service]
final class CombinatorVariantsService
{
    /**
     * Returns the first of two concurrent calls to complete successfully. When the
     * first call has failed in the journal, the second call's value wins.
     */
    #[Handler]
    public function any(Context $ctx): string
    {
        $first = $ctx->serviceCallAsync('Backend', 'unreliable');
        $second = $ctx->serviceCallAsync('Backend', 'reliable');

        $value = $ctx->awaitAny($first, $second);

        return \is_string($value) ? $value : '';
    }

    /**
     * Requires both concurrent calls to succeed. If either has failed in the journal,
     * {@see Context::awaitAllSucceeded} rethrows that failure and the handler fails.
     */
    #[Handler]
    public function allSucceeded(Context $ctx): string
    {
        $first = $ctx->serviceCallAsync('Backend', 'unreliable');
        $second = $ctx->serviceCallAsync('Backend', 'reliable');

        $values = $ctx->awaitAllSucceeded([$first, $second]);

        return \implode(',', \array_map(
            static fn (mixed $value): string => \is_string($value) ? $value : '',
            $values,
        ));
    }
}
