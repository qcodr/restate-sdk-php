<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Vm;

/**
 * The SDK's local view of object/workflow state, seeded from `StartMessage.state_map`.
 *
 * State reads are served without a runtime round-trip when the answer is known
 * locally: a key present in the map, or any key when the map is exhaustive
 * (`partial_state = false`). Writes (set/clear/clearAll) update this view so later
 * reads within the same invocation observe them. When the map is partial and a key
 * is unknown, {@see get} reports "unknown" and the caller falls back to a lazy read.
 */
final class EagerStateStore
{
    /** @var array<string, string> */
    private array $values;

    /** @var array<string, true> keys explicitly cleared this invocation */
    private array $cleared = [];

    /**
     * @param array<string, string> $stateMap
     */
    public function __construct(array $stateMap, private bool $partial)
    {
        $this->values = $stateMap;
    }

    /**
     * @return array{0: bool, 1: bool, 2: ?string} [known, found, value]
     *         known=false means the caller must perform a lazy read
     */
    public function get(string $key): array
    {
        if (\array_key_exists($key, $this->values)) {
            return [true, true, $this->values[$key]];
        }
        if (isset($this->cleared[$key]) || !$this->partial) {
            return [true, false, null];
        }

        return [false, false, null];
    }

    public function set(string $key, string $value): void
    {
        $this->values[$key] = $value;
        unset($this->cleared[$key]);
    }

    public function clear(string $key): void
    {
        unset($this->values[$key]);
        $this->cleared[$key] = true;
    }

    public function clearAll(): void
    {
        $this->values = [];
        $this->cleared = [];
        $this->partial = false; // state is now fully known: empty
    }

    /**
     * @return array{0: bool, 1: list<string>} [known, keys]
     */
    public function keys(): array
    {
        if ($this->partial) {
            return [false, []];
        }

        return [true, \array_keys($this->values)];
    }
}
