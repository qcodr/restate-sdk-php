<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Support;

use Qcodr\Restate\Sdk\Endpoint\StreamTransport;

/**
 * A network-free {@see StreamTransport} test double. It serves a pre-built queue of
 * inbound chunks (typically encoded with {@see JournalBuilder}), captures every byte
 * the driver writes, and reports EOF once the queue drains.
 *
 * To prove ordering — e.g. that a command was streamed out before a completion was
 * fed in — it snapshots the output written so far at each {@see read}, so a test can
 * assert what had already been emitted at the moment a given chunk was delivered.
 *
 * @internal test support
 */
final class BufferedStreamTransport implements StreamTransport
{
    /** @var list<string> inbound chunks delivered in order, then EOF */
    private array $inbound;

    private string $written = '';

    private bool $closed = false;

    /** @var list<string> output-so-far captured at each read() call, in read order */
    private array $readSnapshots = [];

    /**
     * @param list<string> $inbound the chunks to hand back from {@see read}, in order
     */
    public function __construct(array $inbound = [])
    {
        $this->inbound = $inbound;
    }

    /** Enqueues another inbound chunk to be returned by a later {@see read}. */
    public function push(string $chunk): self
    {
        $this->inbound[] = $chunk;

        return $this;
    }

    public function read(): ?string
    {
        $this->readSnapshots[] = $this->written;

        if ($this->inbound === []) {
            return null;
        }

        return \array_shift($this->inbound);
    }

    public function write(string $bytes): void
    {
        $this->written .= $bytes;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    /** All bytes the driver has written so far. */
    public function written(): string
    {
        return $this->written;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * The output that had been written at the moment of the n-th {@see read} call
     * (0-indexed). Read #0 is the first journal read, so a completion fed as the n-th
     * inbound chunk is observed at read #n with the prior commands already captured.
     */
    public function outputAtRead(int $index): string
    {
        return $this->readSnapshots[$index] ?? '';
    }
}
