<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Protocol\Message;

/**
 * The result payload carried by a notification, mirroring the `result` oneof of
 * the protocol's `NotificationTemplate`.
 */
enum NotificationResult: string
{
    case None = 'none';
    case Void = 'void';
    case Value = 'value';
    case Failure = 'failure';
    case InvocationId = 'invocation_id';
    case StateKeys = 'state_keys';
}
