<?php

declare(strict_types=1);

namespace Restate\Sdk\Protocol\Message;

/**
 * How a set of child futures are combined in a {@see Future} await tree.
 * Semantics follow JavaScript promise combinators.
 */
enum CombinatorType: int
{
    case Unknown = 0;                  // treated as FirstCompleted
    case FirstCompleted = 1;           // Promise.race
    case AllCompleted = 2;             // Promise.allSettled
    case FirstSucceededOrAllFailed = 3; // Promise.any
    case AllSucceededOrFirstFailed = 4; // Promise.all
}
