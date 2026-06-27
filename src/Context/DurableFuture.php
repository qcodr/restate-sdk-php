<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Context;

use Closure;
use Qcodr\Restate\Sdk\Error\TerminalException;
use Qcodr\Restate\Sdk\Protocol\Message\Notification;
use Qcodr\Restate\Sdk\Protocol\Message\NotificationResult;
use Qcodr\Restate\Sdk\Vm\StateMachine;

/**
 * A pending durable result (a call result, a timer, an awakeable, or a named signal).
 *
 * Awaiting it returns the value if the completion is already in the replayed
 * journal, decoding the payload via the supplied decoder; otherwise the state
 * machine suspends the invocation. A failure result is raised as a
 * {@see TerminalException}.
 *
 * Three addressing modes: a completion id (calls, timers, runs), a signal index
 * (awakeables), or a user-chosen signal name (named signals). When {@see $signalName}
 * is set the future routes through the VM's named-signal table regardless of $id.
 */
final class DurableFuture
{
    /**
     * @param (Closure(string): mixed)|null $decoder    maps the raw value bytes to a PHP value
     * @param ?string                       $signalName when set, the future awaits this named
     *                                                  signal instead of a completion/signal id
     */
    public function __construct(
        private readonly StateMachine $vm,
        private readonly int $id,
        private readonly bool $isSignal,
        private readonly ?Closure $decoder = null,
        private readonly ?string $signalName = null,
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

    /** Whether this future awaits a user-chosen named signal (rather than a completion/signal id). */
    public function isNamedSignal(): bool
    {
        return $this->signalName !== null;
    }

    public function signalName(): ?string
    {
        return $this->signalName;
    }

    /** Whether the result is already available (in the replayed journal). */
    public function isReady(): bool
    {
        if ($this->signalName !== null) {
            return $this->vm->isNamedSignalReady($this->signalName);
        }

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

        return $this->peek()->resultKind === NotificationResult::Failure;
    }

    public function await(): mixed
    {
        $notification = match (true) {
            $this->signalName !== null => $this->vm->awaitNamedSignal($this->signalName),
            $this->isSignal => $this->vm->awaitSignal($this->id),
            default => $this->vm->awaitCompletion($this->id),
        };

        return $this->resolve($notification);
    }

    /** Resolves an already-ready future without suspending (peeks; does not consume). */
    public function take(): mixed
    {
        return $this->resolve($this->peek());
    }

    /** Reads the ready notification from the matching VM table without consuming it. */
    private function peek(): Notification
    {
        if ($this->signalName !== null) {
            return $this->vm->peekNamedSignal($this->signalName);
        }

        return $this->isSignal
            ? $this->vm->peekSignal($this->id)
            : $this->vm->peekCompletion($this->id);
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
