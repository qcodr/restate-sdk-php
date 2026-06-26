<?php

declare(strict_types=1);

namespace Restate\Sdk\Context;

/**
 * The context for shared (read-only) Virtual Object handlers. Adds the object key
 * and read access to its state; writes are not available here.
 */
interface SharedObjectContext extends Context
{
    /** The key of the Virtual Object this invocation is bound to. */
    public function key(): string;

    public function get(string $key): mixed;

    /**
     * @return list<string>
     */
    public function stateKeys(): array;
}
