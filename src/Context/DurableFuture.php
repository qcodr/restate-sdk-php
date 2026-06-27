<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Context;

use Closure;
use Qcodr\Restate\Sdk\Error\TerminalException;
use Qcodr\Restate\Sdk\Protocol\Message\Notification;
use Qcodr\Restate\Sdk\Protocol\Message\NotificationResult;
use Qcodr\Restate\Sdk\Vm\StateMachine;

/**
 * A pending durable result (a call result, a timer, or an awakeable).
 *
 * Awaiting it returns the value if the completion is already in the replayed
 * journal, decoding the payload via the supplied decoder; otherwise the state
 * machine suspends the invocation. A failure result is raised as a
 * {@see TerminalException}.
 */
final class DurableFuture
{
    /**
     * @param (Closure(string): mixed)|null $decoder maps the raw value bytes to a PHP value
     */
    public function __construct(
        private readonly StateMachine $vm,
        private readonly int $id,
        private readonly bool $isSignal,
        private readonly ?Closure $decoder = null,
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function isSignal(): bool
    {
        return $this->isSignal;
    }

    /** Whether the result is already available (in the replayed journal). */
    public function isReady(): bool
    {
        return $this->isSignal
            ? $this->vm->isSignalReady($this->id)
            : $this->vm->isCompletionReady($this->id);
    }

    /**
     * Whether the result is already available AND is a failure.
     *
     * Lets a combinator inspect a ready future's outcome without raising the
     * {@see TerminalException} that {@see await()} / {@see take()} would. Returns
     * false while the future is still pending. No decoder runs and no state is
     * consumed, so it is side-effect free.
     */
    public function isFailed(): bool
    {
        if (!$this->isReady()) {
            return false;
        }

        $notification = $this->isSignal
            ? $this->vm->peekSignal($this->id)
            : $this->vm->peekCompletion($this->id);

        return $notification->resultKind === NotificationResult::Failure;
    }

    public function await(): mixed
    {
        $notification = $this->isSignal
            ? $this->vm->awaitSignal($this->id)
            : $this->vm->awaitCompletion($this->id);

        return $this->resolve($notification);
    }

    /** Resolves an already-ready future without suspending (peeks; does not consume). */
    public function take(): mixed
    {
        $notification = $this->isSignal
            ? $this->vm->peekSignal($this->id)
            : $this->vm->peekCompletion($this->id);

        return $this->resolve($notification);
    }

    private function resolve(Notification $notification): mixed
    {
        return match ($notification->resultKind) {
            NotificationResult::Failure => throw new TerminalException(
                $notification->failure->message ?? 'terminal failure',
                $notification->failure->code ?? TerminalException::DEFAULT_CODE,
                metadata: $notification->failure->metadata ?? [],
            ),
            NotificationResult::Value => $this->decoder !== null
                ? ($this->decoder)($notification->value ?? '')
                : $notification->value,
            NotificationResult::InvocationId => $notification->invocationId,
            NotificationResult::StateKeys => $notification->stateKeys->keys ?? [],
            NotificationResult::Void, NotificationResult::None => null,
        };
    }
}
