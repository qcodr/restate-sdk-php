<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Context;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Psr\Log\LogLevel;
use Qcodr\Restate\Sdk\Context\ReplayAwareLogger;
use Stringable;

/**
 * Verifies the replay-aware log gate: records emitted while the invocation is
 * processing are forwarded to the inner logger, while records emitted during replay
 * are dropped (they already shipped on an earlier slice). The processing decision is
 * re-evaluated on every call.
 */
final class ReplayAwareLoggerTest extends TestCase
{
    public function testForwardsLevelMessageAndContextWhileProcessing(): void
    {
        $inner = $this->recordingLogger();
        $logger = new ReplayAwareLogger($inner, static fn (): bool => true);

        $logger->log(LogLevel::INFO, 'live line', ['user' => 'ada']);

        self::assertSame(
            [['level' => LogLevel::INFO, 'message' => 'live line', 'context' => ['user' => 'ada']]],
            $inner->records,
        );
    }

    public function testSuppressesRecordsWhileReplaying(): void
    {
        $inner = $this->recordingLogger();
        $logger = new ReplayAwareLogger($inner, static fn (): bool => false);

        $logger->log(LogLevel::ERROR, 'replay line', ['x' => 'y']);

        self::assertSame([], $inner->records, 'a record emitted during replay is dropped');
    }

    public function testTraitLevelHelpersAreGatedToo(): void
    {
        $inner = $this->recordingLogger();
        $logger = new ReplayAwareLogger($inner, static fn (): bool => false);

        // error() routes through log(), so the gate applies to the PSR-3 helpers too.
        $logger->error('via helper', ['code' => 7]);

        self::assertSame([], $inner->records);
    }

    public function testProcessingDecisionIsReEvaluatedPerCall(): void
    {
        $inner = $this->recordingLogger();
        $processing = false;
        $logger = new ReplayAwareLogger(
            $inner,
            static function () use (&$processing): bool {
                return $processing;
            },
        );

        $logger->info('first');  // replaying -> suppressed
        $processing = true;
        $logger->info('second'); // processing -> forwarded

        self::assertSame(['second'], \array_column($inner->records, 'message'));
    }

    /**
     * An inner PSR-3 logger that appends every forwarded record onto a public array
     * the test can assert against.
     *
     * @return LoggerInterface&object{records: list<array{level: mixed, message: string, context: array<string, mixed>}>}
     */
    private function recordingLogger(): LoggerInterface
    {
        return new class () implements LoggerInterface {
            use LoggerTrait;

            /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
            public array $records = [];

            /**
             * @param mixed                $level
             * @param array<string, mixed> $context
             */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };
    }
}
