<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Protocol;

/**
 * `ErrorBehavior` (protocol enum, since V7): tells the runtime what to do when the
 * SDK closes an invocation with an {@see \Qcodr\Restate\Sdk\Protocol\Message\ErrorMessage}.
 *
 * {@see Retry} is the zero value so that an unset `behavior` field is interpreted as
 * a plain retry, matching the default of earlier protocol versions.
 */
enum ErrorBehavior: int
{
    /** Retry the invocation (optionally honouring `next_retry_delay`). */
    case Retry = 0;
    /** Pause the invocation instead of retrying. */
    case Pause = 1;
    /** Fail the invocation terminally, without retrying. */
    case Fail = 2;
}
