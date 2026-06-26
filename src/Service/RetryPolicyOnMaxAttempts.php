<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Service;

/**
 * What Restate does to an invocation once its retry policy exhausts the configured
 * maximum number of attempts: pause it for manual intervention, or kill it outright.
 *
 * Advertised in the discovery manifest (schema v4+) as `retryPolicyOnMaxAttempts`.
 */
enum RetryPolicyOnMaxAttempts: string
{
    case Pause = 'PAUSE';
    case Kill = 'KILL';
}
