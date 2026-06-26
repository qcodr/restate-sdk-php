<?php

declare(strict_types=1);

namespace Restate\Sdk\Context;

use Closure;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Stringable;

/**
 * A PSR-3 logger decorator that suppresses log records while the invocation is
 * replaying, forwarding only those emitted during processing.
 *
 * This is the PHP analogue of the Rust SDK's replay-aware tracing filter: a handler
 * re-runs from the top on every slice, so logging unconditionally would emit the
 * same lines on each replay. Gating on the state machine's processing phase keeps
 * the log free of replay noise.
 *
 * @see \Restate\Sdk\Vm\StateMachine::isProcessing()
 */
final class ReplayAwareLogger implements LoggerInterface
{
    use LoggerTrait;

    /**
     * @param Closure(): bool $isProcessing returns true only outside replay
     */
    public function __construct(
        private readonly LoggerInterface $inner,
        private readonly Closure $isProcessing,
    ) {
    }

    /**
     * @param mixed                $level
     * @param array<string, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (!($this->isProcessing)()) {
            return; // replaying: this line already shipped on a previous slice
        }

        $this->inner->log($level, $message, $context);
    }
}
