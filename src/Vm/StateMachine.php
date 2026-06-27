<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Vm;

use Closure;
use Qcodr\Restate\Sdk\Error\CancelledException;
use Qcodr\Restate\Sdk\Protocol\ErrorBehavior;
use Qcodr\Restate\Sdk\Protocol\Frame;
use Qcodr\Restate\Sdk\Protocol\Message\AwaitingOnMessage;
use Qcodr\Restate\Sdk\Protocol\Message\CallCommand;
use Qcodr\Restate\Sdk\Protocol\Message\ClearAllStateCommand;
use Qcodr\Restate\Sdk\Protocol\Message\ClearStateCommand;
use Qcodr\Restate\Sdk\Protocol\Message\CombinatorType;
use Qcodr\Restate\Sdk\Protocol\Message\CompleteAwakeableCommand;
use Qcodr\Restate\Sdk\Protocol\Message\CompletePromiseCommand;
use Qcodr\Restate\Sdk\Protocol\Message\EndMessage;
use Qcodr\Restate\Sdk\Protocol\Message\ErrorMessage;
use Qcodr\Restate\Sdk\Protocol\Message\Failure;
use Qcodr\Restate\Sdk\Protocol\Message\Future;
use Qcodr\Restate\Sdk\Protocol\Message\GetEagerStateCommand;
use Qcodr\Restate\Sdk\Protocol\Message\GetEagerStateKeysCommand;
use Qcodr\Restate\Sdk\Protocol\Message\GetLazyStateCommand;
use Qcodr\Restate\Sdk\Protocol\Message\GetLazyStateKeysCommand;
use Qcodr\Restate\Sdk\Protocol\Message\GetPromiseCommand;
use Qcodr\Restate\Sdk\Protocol\Message\Header;
use Qcodr\Restate\Sdk\Protocol\Message\InputCommand;
use Qcodr\Restate\Sdk\Protocol\Message\Notification;
use Qcodr\Restate\Sdk\Protocol\Message\OneWayCallCommand;
use Qcodr\Restate\Sdk\Protocol\Message\OutgoingMessage;
use Qcodr\Restate\Sdk\Protocol\Message\OutputCommand;
use Qcodr\Restate\Sdk\Protocol\Message\PeekPromiseCommand;
use Qcodr\Restate\Sdk\Protocol\Message\ProposeRunCompletion;
use Qcodr\Restate\Sdk\Protocol\Message\RunCommand;
use Qcodr\Restate\Sdk\Protocol\Message\SendSignalCommand;
use Qcodr\Restate\Sdk\Protocol\Message\SetStateCommand;
use Qcodr\Restate\Sdk\Protocol\Message\SleepCommand;
use Qcodr\Restate\Sdk\Protocol\Message\StartMessage;
use Qcodr\Restate\Sdk\Protocol\Message\SuspensionMessage;
use Qcodr\Restate\Sdk\Protocol\MessageCodec;
use Qcodr\Restate\Sdk\Protocol\MessageType;
use Qcodr\Restate\Sdk\Protocol\ProtocolException;
use Qcodr\Restate\Sdk\Protocol\ServiceProtocolVersion;

/**
 * The invocation state machine: the pure-PHP equivalent of the Rust shared-core VM.
 *
 * It owns the wire protocol on behalf of the SDK — decoding the replayed journal,
 * tracking the replay cursor and completion table, issuing commands, deciding when
 * to suspend, and framing the terminal message. It performs no I/O: the endpoint
 * feeds it request bytes ({@see notifyInput}) and drains response bytes
 * ({@see takeOutput}).
 *
 * Transport model: request/response. The runtime delivers `StartMessage` plus
 * exactly `known_entries` journal frames, then EOF. Commands the handler issues
 * while the command cursor is behind the journal are replays (not re-sent);
 * completions for them are read from the table. Once the cursor passes the journal
 * the handler is the source of truth and new commands flow out — but no further
 * notifications can arrive, so awaiting an unresolved result suspends.
 */
final class StateMachine
{
    private string $inputBuffer = '';
    private bool $inputClosed = false;
    private bool $parsed = false;

    private ?StartMessage $start = null;
    private ?InputCommand $input = null;
    private ?EagerStateStore $eager = null;

    private int $knownCommands = 0;
    private int $commandIndex = 0;
    private int $nextCompletionId = 1;
    private int $nextSignalId = self::FIRST_USER_SIGNAL_ID;

    /** @var list<MessageType> replayed command types, by journal command index */
    private array $journalCommandTypes = [];

    /** Defensive cap on replayed entries to bound the parse loop (CPU-DoS). */
    private const MAX_KNOWN_ENTRIES = 100_000;

    /** @var array<int, Notification> completions keyed by completion id */
    private array $completions = [];
    /** @var array<int, Notification> signals keyed by signal index */
    private array $signals = [];
    /**
     * Invocation-id completion ids of the calls this handler has issued, in order. On
     * cancellation they are used to propagate the cancel to those child invocations
     * (implicit cancellation), so a cancelled parent tears down the calls it spawned.
     *
     * @var list<int>
     */
    private array $trackedInvocationIdCompletions = [];

    private VmState $state = VmState::WaitingPreFlight;

    private readonly Suspender $suspender;
    private readonly OutputSink $sink;

    /** Built-in signals reserve indexes 0..16; user signals (awakeables) start here. */
    private const FIRST_USER_SIGNAL_ID = 17;

    /** Built-in CANCEL signal (BuiltInSignal.CANCEL) delivered to observe cancellation. */
    private const CANCEL_SIGNAL_ID = 1;

    /**
     * @param Suspender|null  $suspender how an await point parks; defaults to the
     *                                   request/response {@see ThrowingSuspender}.
     * @param OutputSink|null $sink      where encoded frames go; defaults to the
     *                                   buffering {@see BufferingOutputSink}.
     */
    public function __construct(
        private readonly ServiceProtocolVersion $version,
        ?Suspender $suspender = null,
        ?OutputSink $sink = null,
    ) {
        $this->suspender = $suspender ?? new ThrowingSuspender();
        $this->sink = $sink ?? new BufferingOutputSink();
    }

    public function protocolVersion(): ServiceProtocolVersion
    {
        return $this->version;
    }

    // --- Input bootstrapping ---

    public function notifyInput(string $bytes): void
    {
        $this->inputBuffer .= $bytes;
        $this->tryParse();
    }

    public function notifyInputClosed(): void
    {
        $this->inputClosed = true;
        $this->tryParse();
    }

    public function isReadyToExecute(): bool
    {
        $this->tryParse();

        return $this->parsed;
    }

    private function tryParse(): void
    {
        if ($this->parsed) {
            // The journal is already parsed. In request/response transport no further
            // bytes arrive, so this is a no-op; in streaming transport the runtime keeps
            // delivering notifications, which are routed into the completion/signal
            // tables here (the command cursor is never advanced by them).
            $this->ingestTrailing();

            return;
        }

        $offset = 0;
        $startFrame = MessageCodec::consume($this->inputBuffer, $offset);
        if ($startFrame === null) {
            return;
        }
        if ($startFrame->type() !== MessageType::Start) {
            throw new ProtocolException('Expected StartMessage as the first frame');
        }
        $start = StartMessage::decode($startFrame->payload);
        if ($start->knownEntries > self::MAX_KNOWN_ENTRIES) {
            throw new ProtocolException('known_entries exceeds the maximum');
        }

        $cursor = $offset;
        $consumed = 0;
        $knownCommands = 0;
        $input = null;
        $completions = [];
        $signals = [];
        $commandTypes = [];

        while ($consumed < $start->knownEntries) {
            $frame = MessageCodec::consume($this->inputBuffer, $cursor);
            if ($frame === null) {
                if ($this->inputClosed) {
                    throw new ProtocolException('Journal is shorter than known_entries');
                }

                return; // need more bytes
            }
            $this->classifyJournalFrame($frame, $knownCommands, $input, $completions, $signals, $commandTypes);
            $consumed++;
        }

        // Commit parsed state.
        $this->start = $start;
        $this->input = $input;
        $this->eager = new EagerStateStore($start->stateMap, $start->partialState);
        $this->knownCommands = $knownCommands;
        $this->completions = $completions;
        $this->signals = $signals;
        $this->journalCommandTypes = $commandTypes;
        $this->parsed = true;
        // Drop the (up to 64 MB) parsed journal; keep only any bytes past it. In
        // request/response the cursor sits at end-of-buffer so this frees everything,
        // exactly as before; in streaming it preserves frames that arrived early.
        $this->inputBuffer = \substr($this->inputBuffer, $cursor);
        $this->ingestTrailing();
    }

    /**
     * Routes any complete notification frames buffered past the replayed journal into
     * the completion/signal tables, leaving a partial trailing frame for the next call.
     * Streaming transport only; request/response never accumulates trailing bytes.
     */
    private function ingestTrailing(): void
    {
        if ($this->inputBuffer === '') {
            return;
        }

        $offset = 0;
        while (($frame = MessageCodec::consume($this->inputBuffer, $offset)) !== null) {
            $type = $frame->type();
            if ($type !== null && $type->isNotification()) {
                $this->routeNotification(
                    Notification::decode($frame->payload),
                    $this->completions,
                    $this->signals,
                );
            }
        }

        if ($offset > 0) {
            $this->inputBuffer = \substr($this->inputBuffer, $offset);
        }
    }

    /**
     * @param int               $knownCommands by-ref command counter
     * @param InputCommand|null  $input         by-ref captured input command
     * @param array<int, Notification> $completions  by-ref
     * @param array<int, Notification> $signals      by-ref
     * @param list<MessageType>        $commandTypes by-ref, appended per command frame
     */
    private function classifyJournalFrame(
        Frame $frame,
        int &$knownCommands,
        ?InputCommand &$input,
        array &$completions,
        array &$signals,
        array &$commandTypes,
    ): void {
        $type = $frame->type();
        if ($type === MessageType::InputCommand) {
            $input = InputCommand::decode($frame->payload);
            $knownCommands++;
            $commandTypes[] = $type;

            return;
        }
        if ($type !== null && $type->isCommand()) {
            $knownCommands++;
            $commandTypes[] = $type;

            return;
        }
        if ($type !== null && $type->isNotification()) {
            $this->routeNotification(Notification::decode($frame->payload), $completions, $signals);

            return;
        }
        // Control frames inside the journal (e.g. ProposeRunCompletionAck) are ignored;
        // their resolved value arrives separately as a notification.
    }

    /**
     * @param array<int, Notification> $completions by-ref
     * @param array<int, Notification> $signals     by-ref
     */
    private function routeNotification(Notification $notification, array &$completions, array &$signals): void
    {
        if ($notification->completionId !== null) {
            $completions[$notification->completionId] = $notification;
        } elseif ($notification->signalId !== null) {
            $signals[$notification->signalId] = $notification;
        }
    }

    // --- Execution ---

    public function sysInput(): InvocationInput
    {
        $this->ensureParsed();
        $this->commandIndex++; // Input is journal command #0

        $start = $this->start;
        $input = $this->input;
        if ($start === null || $input === null) {
            throw new ProtocolException('Invocation has no input command');
        }

        return new InvocationInput(
            $start->id,
            $start->key,
            $input->body,
            $input->headers,
            $start->randomSeed,
            $start->idempotencyKey,
            $start->retryCount,
        );
    }

    /**
     * The retry count since the last stored journal entry, as reported by the
     * runtime in the StartMessage. Note this count is best-effort: it is not durably
     * stored and may reset if Restate crashes or changes leader.
     */
    public function retryCount(): int
    {
        return $this->requireStart()->retryCount;
    }

    /**
     * Reads a state key.
     *
     * @return array{0: bool, 1: ?string} [found, value]
     */
    public function sysGetState(string $key): array
    {
        $this->ensureParsed();
        [$known, $found, $value] = $this->requireEager()->get($key);

        if ($known) {
            $this->recordCommand($found
                ? GetEagerStateCommand::found($key, (string) $value)
                : GetEagerStateCommand::empty($key));

            return [$found, $found ? $value : null];
        }

        // Partial state, key unknown: lazy read (completable).
        $completionId = $this->allocateCompletionId();
        $this->recordCommand(new GetLazyStateCommand($key, $completionId));
        $notification = $this->awaitCompletion($completionId);
        $found = $notification->value !== null;

        return [$found, $notification->value];
    }

    /**
     * @return list<string>
     */
    public function sysGetStateKeys(): array
    {
        $this->ensureParsed();
        [$known, $keys] = $this->requireEager()->keys();

        if ($known) {
            $this->recordCommand(new GetEagerStateKeysCommand($keys));

            return $keys;
        }

        $completionId = $this->allocateCompletionId();
        $this->recordCommand(new GetLazyStateKeysCommand($completionId));
        $notification = $this->awaitCompletion($completionId);

        return $notification->stateKeys->keys ?? [];
    }

    public function sysSetState(string $key, string $value): void
    {
        $this->ensureParsed();
        $this->requireEager()->set($key, $value);
        $this->recordCommand(new SetStateCommand($key, $value));
    }

    public function sysClearState(string $key): void
    {
        $this->ensureParsed();
        $this->requireEager()->clear($key);
        $this->recordCommand(new ClearStateCommand($key));
    }

    public function sysClearAllState(): void
    {
        $this->ensureParsed();
        $this->requireEager()->clearAll();
        $this->recordCommand(new ClearAllStateCommand());
    }

    public function sysSleep(int $wakeUpTimeMillis): int
    {
        $this->ensureParsed();
        $completionId = $this->allocateCompletionId();
        $this->recordCommand(new SleepCommand($wakeUpTimeMillis, $completionId));

        return $completionId;
    }

    /**
     * Issues a request-response call.
     *
     * @param list<Header> $headers
     *
     * @return array{0: int, 1: int} [invocationIdCompletionId, resultCompletionId]
     */
    public function sysCall(
        string $serviceName,
        string $handlerName,
        string $key,
        string $parameter,
        array $headers = [],
        ?string $idempotencyKey = null,
    ): array {
        $this->ensureParsed();
        $invocationIdCompletionId = $this->allocateCompletionId();
        $resultCompletionId = $this->allocateCompletionId();
        $this->recordCommand(new CallCommand(
            $serviceName,
            $handlerName,
            $parameter,
            $invocationIdCompletionId,
            $resultCompletionId,
            $key,
            $idempotencyKey,
            $headers,
        ));
        // Remember the callee so a cancel of this handler propagates to it.
        $this->trackedInvocationIdCompletions[] = $invocationIdCompletionId;

        return [$invocationIdCompletionId, $resultCompletionId];
    }

    /**
     * Issues a one-way (fire-and-forget) call, optionally delayed.
     *
     * @param list<Header> $headers
     *
     * @return int the invocation-id completion id
     */
    public function sysOneWayCall(
        string $serviceName,
        string $handlerName,
        string $key,
        string $parameter,
        int $invokeTimeMillis = 0,
        array $headers = [],
        ?string $idempotencyKey = null,
    ): int {
        $this->ensureParsed();
        $invocationIdCompletionId = $this->allocateCompletionId();
        $this->recordCommand(new OneWayCallCommand(
            $serviceName,
            $handlerName,
            $parameter,
            $invocationIdCompletionId,
            $invokeTimeMillis,
            $key,
            $idempotencyKey,
            $headers,
        ));

        return $invocationIdCompletionId;
    }

    public function sysRun(string $name): int
    {
        $this->ensureParsed();
        $completionId = $this->allocateCompletionId();
        $this->recordCommand(new RunCommand($completionId, $name));

        return $completionId;
    }

    // --- Workflow durable promises ---

    public function sysGetPromise(string $key): int
    {
        $this->ensureParsed();
        $completionId = $this->allocateCompletionId();
        $this->recordCommand(new GetPromiseCommand($key, $completionId));

        return $completionId;
    }

    public function sysPeekPromise(string $key): int
    {
        $this->ensureParsed();
        $completionId = $this->allocateCompletionId();
        $this->recordCommand(new PeekPromiseCommand($key, $completionId));

        return $completionId;
    }

    public function sysResolvePromise(string $key, string $value): void
    {
        $this->ensureParsed();
        $completionId = $this->allocateCompletionId();
        $this->recordCommand(CompletePromiseCommand::resolve($key, $value, $completionId));
    }

    public function sysRejectPromise(string $key, Failure $failure): void
    {
        $this->ensureParsed();
        $completionId = $this->allocateCompletionId();
        $this->recordCommand(CompletePromiseCommand::reject($key, $failure, $completionId));
    }

    public function proposeRunCompletionSuccess(int $completionId, string $value): void
    {
        $this->appendOutput(ProposeRunCompletion::success($completionId, $value));
    }

    public function proposeRunCompletionFailure(int $completionId, Failure $failure): void
    {
        $this->appendOutput(ProposeRunCompletion::failure($completionId, $failure));
    }

    /**
     * Creates an awakeable: a signal slot plus the public id another invocation can
     * use to complete it. The id is `sign_1` + base64url(invocationId ++ uint32be(idx)).
     *
     * @return array{0: string, 1: int} [awakeableId, signalId]
     */
    public function createAwakeable(): array
    {
        $this->ensureParsed();
        $signalId = $this->nextSignalId++;

        return [AwakeableId::encode($this->requireStart()->id, $signalId), $signalId];
    }

    public function sysCompleteAwakeable(CompleteAwakeableCommand $command): void
    {
        $this->ensureParsed();
        $this->recordCommand($command);
    }

    /** Cancels another invocation by sending it the built-in CANCEL signal. */
    public function sysCancel(string $invocationId): void
    {
        $this->ensureParsed();
        $this->recordCommand(SendSignalCommand::cancel($invocationId));
    }

    // --- Completion / suspension ---

    public function isCompletionReady(int $completionId): bool
    {
        return isset($this->completions[$completionId]);
    }

    /**
     * Reads a ready completion without consuming it. The entry stays in the table, so
     * repeated reads (e.g. DurableFuture's isFailed() then take()) are safe; the table
     * is bounded per invocation and freed when the slice ends.
     */
    public function peekCompletion(int $completionId): Notification
    {
        if (!isset($this->completions[$completionId])) {
            throw new ProtocolException("Completion {$completionId} is not available");
        }

        return $this->completions[$completionId];
    }

    /**
     * Whether the invocation has been cancelled: the runtime delivered the built-in
     * CANCEL signal (index 1) into the signals table.
     *
     * Caveat (request/response transport): a cancel is only observable here if the
     * runtime re-invoked the SDK with the CANCEL signal already in the replayed
     * journal. The SDK cannot observe a cancel that arrives mid-slice; it surfaces on
     * the next replay, when the handler reaches an await point that is not yet ready.
     */
    public function isCancelled(): bool
    {
        return isset($this->signals[self::CANCEL_SIGNAL_ID]);
    }

    /**
     * Fails the current await with {@see CancelledException}, first propagating the cancel
     * to the calls this handler issued (implicit cancellation): every tracked callee whose
     * invocation id is already known is sent the built-in CANCEL signal, so a cancelled
     * parent tears down the children it is blocked on rather than leaking them. Mirrors the
     * cancellation branch of sdk-shared-core's `do_await`.
     */
    public function raiseCancellation(): never
    {
        foreach ($this->trackedInvocationIdCompletions as $completionId) {
            $invocationId = ($this->completions[$completionId] ?? null)?->invocationId;
            if ($invocationId !== null) {
                $this->recordCommand(SendSignalCommand::cancel($invocationId));
            }
        }
        $this->trackedInvocationIdCompletions = [];

        throw new CancelledException();
    }

    /** Returns the completion if ready, otherwise parks (or fails if cancelled). */
    public function awaitCompletion(int $completionId): Notification
    {
        if (isset($this->completions[$completionId])) {
            return $this->completions[$completionId];
        }
        if ($this->isCancelled()) {
            $this->raiseCancellation();
        }

        // Request/response parks by throwing (the lines below are unreachable there);
        // streaming returns only once the driver has fed the completion (or a cancel),
        // as enforced by the readiness predicate, so the await runs on straight-line.
        $this->parkOn(
            Future::forCompletion($completionId),
            fn (): bool => isset($this->completions[$completionId]) || $this->isCancelled(),
        );

        if ($this->isCancelled()) {
            $this->raiseCancellation(); // cancel won the race
        }

        return $this->peekCompletion($completionId); // the driver guarantees its presence
    }

    public function isSignalReady(int $signalId): bool
    {
        return isset($this->signals[$signalId]);
    }

    public function awaitSignal(int $signalId): Notification
    {
        if (isset($this->signals[$signalId])) {
            return $this->signals[$signalId];
        }
        if ($this->isCancelled()) {
            $this->raiseCancellation();
        }

        $this->parkOn(
            Future::forSignal($signalId),
            fn (): bool => isset($this->signals[$signalId]) || $this->isCancelled(),
        );

        if ($this->isCancelled()) {
            $this->raiseCancellation(); // cancel won the race
        }

        return $this->peekSignal($signalId); // the driver guarantees its presence
    }

    /** Reads a ready signal without consuming it (non-destructive; see {@see peekCompletion}). */
    public function peekSignal(int $signalId): Notification
    {
        if (!isset($this->signals[$signalId])) {
            throw new ProtocolException("Signal {$signalId} is not available");
        }

        return $this->signals[$signalId];
    }

    /**
     * Parks awaiting the first of several results to complete (race semantics).
     *
     * @param list<int>       $completionIds
     * @param list<int>       $signalIds
     * @param Closure(): bool $isResolved    the combinator's readiness predicate, supplied
     *                                       by the caller; the streaming driver resumes only
     *                                       once it holds
     */
    public function suspendAny(array $completionIds, array $signalIds, Closure $isResolved): void
    {
        $this->parkOn(new Future($completionIds, $signalIds, [], [], CombinatorType::FirstCompleted), $isResolved);
    }

    /**
     * Parks awaiting every result to complete.
     *
     * @param list<int>       $completionIds
     * @param list<int>       $signalIds
     * @param Closure(): bool $isResolved
     */
    public function suspendAll(array $completionIds, array $signalIds, Closure $isResolved): void
    {
        $this->parkOn(new Future($completionIds, $signalIds, [], [], CombinatorType::AllCompleted), $isResolved);
    }

    /**
     * Parks awaiting the first result to complete *successfully* (Promise.any): the
     * combinator resolves on the first success, or once every awaited result has
     * failed.
     *
     * @param list<int>       $completionIds
     * @param list<int>       $signalIds
     * @param Closure(): bool $isResolved
     */
    public function suspendAnySucceeded(array $completionIds, array $signalIds, Closure $isResolved): void
    {
        $this->parkOn(new Future($completionIds, $signalIds, [], [], CombinatorType::FirstSucceededOrAllFailed), $isResolved);
    }

    /**
     * Parks awaiting every result to complete successfully, short-circuiting on the
     * first failure (Promise.all): the combinator resolves once all awaited results
     * have succeeded, or on the first one to fail.
     *
     * @param list<int>       $completionIds
     * @param list<int>       $signalIds
     * @param Closure(): bool $isResolved
     */
    public function suspendAllSucceeded(array $completionIds, array $signalIds, Closure $isResolved): void
    {
        $this->parkOn(new Future($completionIds, $signalIds, [], [], CombinatorType::AllSucceededOrFirstFailed), $isResolved);
    }

    /**
     * Parks the invocation at an await point: wraps the awaited future in a guard that
     * also waits on the built-in CANCEL signal, then hands it to the {@see Suspender}.
     *
     * The CANCEL guard means the runtime resumes a suspended invocation when it is
     * cancelled (implicit cancellation): on resume {@see isCancelled} is true and the
     * pending await raises {@see CancelledException}. Without it, an invocation blocked
     * only on (say) an awakeable would never be woken by a cancel and would hang.
     *
     * In request/response the suspender writes the suspension and throws to unwind the
     * handler; in streaming it yields the fiber (with its readiness predicate) and
     * returns once the driver has fed a result that satisfies the predicate.
     *
     * @param Closure(): bool $isResolved the await's readiness predicate, forwarded to
     *                                    the suspender so the streaming driver wakes the
     *                                    fiber only when the awaited result is present
     */
    private function parkOn(Future $inner, Closure $isResolved): void
    {
        $this->suspender->park($this, $this->guardWithCancel($inner), $isResolved);
    }

    /**
     * Wraps an awaited future as `FirstCompleted([inner, CANCEL signal])` so the runtime
     * wakes a suspended invocation when it is cancelled. Matching the canonical encoding
     * matters: a single-leaf await (one completion or signal, no combinator) flattens its
     * ids up next to the CANCEL signal — the runtime keys its cancel wake-up off that flat
     * shape — while a real combinator stays nested under the guard.
     */
    private function guardWithCancel(Future $inner): Future
    {
        if ($inner->combinatorType === CombinatorType::Unknown && $inner->nestedFutures === []) {
            return new Future(
                waitingCompletions: $inner->waitingCompletions,
                waitingSignals: [...$inner->waitingSignals, self::CANCEL_SIGNAL_ID],
                waitingNamedSignals: $inner->waitingNamedSignals,
                combinatorType: CombinatorType::FirstCompleted,
            );
        }

        return new Future(
            waitingSignals: [self::CANCEL_SIGNAL_ID],
            nestedFutures: [$inner],
            combinatorType: CombinatorType::FirstCompleted,
        );
    }

    /**
     * Writes the suspension frame for an await tree and closes the VM. Called by the
     * request/response {@see ThrowingSuspender}; the streaming suspender never writes a
     * suspension because the response stays open.
     */
    public function writeSuspension(Future $awaitTree): void
    {
        $this->appendOutput(new SuspensionMessage($awaitTree));
        $this->state = VmState::Closed;
    }

    /**
     * Streaming only: announces the current await tree to the runtime with an
     * {@see AwaitingOnMessage} so it pushes the awaited completions/signals — including
     * external ones the SDK cannot pull, like the CANCEL signal or an awakeable another
     * invocation resolves — onto the open bidi stream. Unlike {@see writeSuspension} it
     * leaves the VM open: the handler stays parked until the driver feeds a result.
     */
    public function writeAwaitingOn(Future $awaitTree): void
    {
        $this->appendOutput(new AwaitingOnMessage($awaitTree));
    }

    // --- Termination ---

    public function sysWriteOutputSuccess(string $value): void
    {
        $this->appendOutput(OutputCommand::success($value));
    }

    public function sysWriteOutputFailure(Failure $failure): void
    {
        $this->appendOutput(OutputCommand::failure($failure));
    }

    public function sysEnd(): void
    {
        $this->appendOutput(new EndMessage());
        $this->state = VmState::Closed;
    }

    public function notifyError(
        int $code,
        string $message,
        string $stacktrace = '',
        ?int $nextRetryDelayMillis = null,
        ErrorBehavior $behavior = ErrorBehavior::Retry,
    ): void {
        $this->appendOutput(new ErrorMessage(
            $code,
            $message,
            $stacktrace,
            null,
            $nextRetryDelayMillis,
            $behavior,
        ));
        $this->state = VmState::Closed;
    }

    public function takeOutput(): string
    {
        // Only a buffering sink retains frames to hand back here; a streaming sink has
        // already pushed each frame to the open response, so there is nothing to drain.
        return $this->sink instanceof BufferingOutputSink ? $this->sink->take() : '';
    }

    public function isProcessing(): bool
    {
        return $this->commandIndex >= $this->knownCommands;
    }

    public function state(): VmState
    {
        if ($this->state === VmState::Closed) {
            return VmState::Closed;
        }
        if (!$this->parsed) {
            return VmState::WaitingPreFlight;
        }

        return $this->isProcessing() ? VmState::Processing : VmState::Replaying;
    }

    // --- Internals ---

    private function recordCommand(OutgoingMessage $command): void
    {
        if ($this->isProcessing()) {
            $this->appendOutput($command);
        } else {
            // Replaying: the command the handler issues must match the journal at this
            // index. A mismatch means non-deterministic user code — fail with a clear
            // JOURNAL_MISMATCH rather than silently feeding it the wrong completion.
            $expected = $this->journalCommandTypes[$this->commandIndex] ?? null;
            if ($expected !== null && $expected !== $command->messageType()) {
                $this->notifyError(
                    ErrorMessage::JOURNAL_MISMATCH,
                    \sprintf(
                        'Journal mismatch at command %d: expected %s but the handler issued %s (non-deterministic code?)',
                        $this->commandIndex,
                        $expected->name,
                        $command->messageType()->name,
                    ),
                );

                throw new SuspendException();
            }
        }
        $this->commandIndex++;
    }

    private function appendOutput(OutgoingMessage $message): void
    {
        $this->sink->write(MessageCodec::encode($message));
    }

    private function allocateCompletionId(): int
    {
        return $this->nextCompletionId++;
    }

    private function ensureParsed(): void
    {
        if (!$this->parsed || $this->eager === null || $this->start === null) {
            throw new ProtocolException('State machine is not ready to execute');
        }
    }

    private function requireEager(): EagerStateStore
    {
        if ($this->eager === null) {
            throw new ProtocolException('State machine is not ready to execute');
        }

        return $this->eager;
    }

    private function requireStart(): StartMessage
    {
        if ($this->start === null) {
            throw new ProtocolException('State machine is not ready to execute');
        }

        return $this->start;
    }
}
