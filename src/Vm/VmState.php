<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Vm;

/**
 * The lifecycle phases of the invocation state machine.
 *
 *   WaitingPreFlight → Replaying → Processing → Closed
 *
 * Replaying vs Processing is derived from the command cursor (see
 * {@see StateMachine::isProcessing()}); this enum captures the externally
 * observable phase, where Closed means a terminator has been emitted.
 */
enum VmState: string
{
    case WaitingPreFlight = 'waiting_preflight';
    case Replaying = 'replaying';
    case Processing = 'processing';
    case Closed = 'closed';
}
