<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Vm;

use Exception;

/**
 * Thrown to unwind user code when the invocation must suspend.
 *
 * In request/response transport, awaiting a result that is not present in the
 * replayed journal cannot make progress within the current slice. The state
 * machine writes a `SuspensionMessage`, then throws this exception so the handler
 * stops; the endpoint flushes the buffered output and closes the response. On the
 * next slice the handler re-runs from the top and replays past the await point.
 *
 * This is control flow, not an error — the endpoint catches it and never maps it
 * to a failure.
 */
final class SuspendException extends Exception
{
}
