<?php

declare(strict_types=1);

namespace Restate\Conformance;

use Restate\Sdk\Context\ObjectContext;
use Restate\Sdk\Context\SharedObjectContext;
use Restate\Sdk\Error\TerminalException;
use Restate\Sdk\Service\Attribute\Handler;
use Restate\Sdk\Service\Attribute\Shared;
use Restate\Sdk\Service\Attribute\VirtualObject;

/**
 * Holds the id of an awakeable created elsewhere, then resolves it on demand.
 *
 * Mirrors the cross-SDK conformance `AwakeableHolder` virtual object: `hold` stashes
 * an awakeable id in state, `hasAwakeable` reports whether one is stored, and
 * `unlock` resolves the stored awakeable with the given payload.
 */
#[VirtualObject(name: 'AwakeableHolder')]
final class AwakeableHolder
{
    private const ID = 'id';

    #[Handler]
    public function hold(ObjectContext $ctx, string $id): void
    {
        $ctx->set(self::ID, $id);
    }

    #[Shared]
    public function hasAwakeable(SharedObjectContext $ctx): bool
    {
        return $ctx->get(self::ID) !== null;
    }

    #[Handler]
    public function unlock(ObjectContext $ctx, string $payload): void
    {
        $id = $ctx->get(self::ID);
        if (!\is_string($id)) {
            throw new TerminalException(
                "No awakeable stored for awakeable holder {$ctx->key()}",
            );
        }

        $ctx->resolveAwakeable($id, $payload);
    }
}
