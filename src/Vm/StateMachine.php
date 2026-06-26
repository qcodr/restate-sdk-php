<?php

declare(strict_types=1);

namespace Restate\Sdk\Vm;

use Restate\Sdk\Error\CancelledException;
use Restate\Sdk\Protocol\ErrorBehavior;
use Restate\Sdk\Protocol\Frame;
use Restate\Sdk\Protocol\Message\CallCommand;
use Restate\Sdk\Protocol\Message\ClearAllStateCommand;
use Restate\Sdk\Protocol\Message\ClearStateCommand;
use Restate\Sdk\Protocol\Message\CombinatorType;
use Restate\Sdk\Protocol\Message\CompleteAwakeableCommand;
use Restate\Sdk\Protocol\Message\CompletePromiseCommand;
use Restate\Sdk\Protocol\Message\EndMessage;
use Restate\Sdk\Protocol\Message\ErrorMessage;
use Restate\Sdk\Protocol\Message\Failure;
use Restate\Sdk\Protocol\Message\Future;
use Restate\Sdk\Protocol\Message\GetEagerStateCommand;
use Restate\Sdk\Protocol\Message\GetEagerStateKeysCommand;
use Restate\Sdk\Protocol\Message\GetLazyStateCommand;
use Restate\Sdk\Protocol\Message\GetLazyStateKeysCommand;
use Restate\Sdk\Protocol\Message\GetPromiseCommand;
use Restate\Sdk\Protocol\Message\Header;
use Restate\Sdk\Protocol\Message\InputCommand;
use Restate\Sdk\Protocol\Message\Notification;
use Restate\Sdk\Protocol\Message\OneWayCallCommand;
use Restate\Sdk\Protocol\Message\OutgoingMessage;
use Restate\Sdk\Protocol\Message\OutputCommand;
use Restate\Sdk\Protocol\Message\PeekPromiseCommand;
use Restate\Sdk\Protocol\Message\ProposeRunCompletion;
use Restate\Sdk\Protocol\Message\RunCommand;
use Restate\Sdk\Protocol\Message\SendSignalCommand;
use Restate\Sdk\Protocol\Message\SetStateCommand;
use Restate\Sdk\Protocol\Message\SleepCommand;
use Restate\Sdk\Protocol\Message\StartMessage;
use Restate\Sdk\Protocol\Message\SuspensionMessage;
use Restate\Sdk\Protocol\MessageCodec;
use Restate\Sdk\Protocol\MessageType;
use Restate\Sdk\Protocol\ProtocolException;
use Restate\Sdk\Protocol\ServiceProtocolVersion;

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

    private string $output = '';
    private VmState $state = VmState::WaitingPreFlight;

    /** Built-in signals reserve indexes 0..16; user signals (awakeables) start here. */
    private const FIRST_USER_SIGNAL_ID = 17;

    /** Built-in CANCEL signal (BuiltInSignal.CANCEL) delivered to observe cancellation. */
    private const CANCEL_SIGNAL_ID = 1;

    public function __construct(private readonly ServiceProtocolVersion $version)
    {
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
        $this->inputBuffer = ''; // the journal is parsed; free the (up to 64 MB) buffer
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
     * use to complete it. The id is `prom_1` + base64url(invocationId ++ uint32be(idx)).
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

    /** Returns the completion if ready, otherwise suspends (or fails if cancelled). */
    public function awaitCompletion(int $completionId): Notification
    {
        if (isset($this->completions[$completionId])) {
            return $this->completions[$completionId];
        }

        if ($this->isCancelled()) {
            throw new CancelledException();
        }

        $this->suspend(Future::forCompletion($completionId));
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
            throw new CancelledException();
        }

        $this->suspend(Future::forSignal($signalId));
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
     * Suspends awaiting the first of several results to complete (race semantics).
     *
     * @param list<int> $completionIds
     * @param list<int> $signalIds
     */
    public function suspendAny(array $completionIds, array $signalIds): never
    {
        $this->suspend(new Future($completionIds, $signalIds, [], [], CombinatorType::FirstCompleted));
    }

    /**
     * Suspends awaiting every result to complete.
     *
     * @param list<int> $completionIds
     * @param list<int> $signalIds
     */
    public function suspendAll(array $completionIds, array $signalIds): never
    {
        $this->suspend(new Future($completionIds, $signalIds, [], [], CombinatorType::AllCompleted));
    }

    /**
     * Suspends awaiting the first result to complete *successfully* (Promise.any):
     * the combinator resolves on the first success, or once every awaited result has
     * failed.
     *
     * @param list<int> $completionIds
     * @param list<int> $signalIds
     */
    public function suspendAnySucceeded(array $completionIds, array $signalIds): never
    {
        $this->suspend(new Future($completionIds, $signalIds, [], [], CombinatorType::FirstSucceededOrAllFailed));
    }

    /**
     * Suspends awaiting every result to complete successfully, short-circuiting on
     * the first failure (Promise.all): the combinator resolves once all awaited
     * results have succeeded, or on the first one to fail.
     *
     * @param list<int> $completionIds
     * @param list<int> $signalIds
     */
    public function suspendAllSucceeded(array $completionIds, array $signalIds): never
    {
        $this->suspend(new Future($completionIds, $signalIds, [], [], CombinatorType::AllSucceededOrFirstFailed));
    }

    /**
     * Emits a suspension for the given await point and unwinds user code.
     *
     * The emitted await tree also waits on the built-in CANCEL signal so the runtime
     * resumes a suspended invocation when it is cancelled (implicit cancellation):
     * on resume {@see isCancelled} is true and the pending await raises
     * {@see CancelledException}. Without this, an invocation blocked only on (say) an
     * awakeable would never be woken by a cancel and would hang forever.
     */
    public function suspend(Future $future): never
    {
        $awaitOn = new Future(
            waitingSignals: [self::CANCEL_SIGNAL_ID],
            nestedFutures: [$future],
            combinatorType: CombinatorType::FirstCompleted,
        );
        $this->appendOutput(new SuspensionMessage($awaitOn));
        $this->state = VmState::Closed;

        throw new SuspendException();
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
        $output = $this->output;
        $this->output = '';

        return $output;
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
        $this->output .= MessageCodec::encode($message);
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
