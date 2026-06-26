<?php

declare(strict_types=1);

namespace Restate\Sdk\Tests\Unit\Endpoint;

use PHPUnit\Framework\TestCase;
use Restate\Sdk\Endpoint\Endpoint;
use Restate\Sdk\Endpoint\HttpRequest;
use Restate\Sdk\Endpoint\RequestProcessor;
use Restate\Sdk\Protocol\MessageCodec;
use Restate\Sdk\Protocol\MessageType;
use Restate\Sdk\Protocol\Protobuf\Reader;
use Restate\Sdk\Protocol\ServiceProtocolVersion;
use Restate\Sdk\Tests\Support\Fixtures\RetryRunService;
use Restate\Sdk\Tests\Support\JournalBuilder;

/**
 * Covers the per-run retry policy (#5): a failing run closure either retries with a
 * computed backoff (an ErrorMessage carrying next_retry_delay) while budget remains,
 * or gives up terminally (a failure run-completion proposal) once attempts are
 * exhausted.
 */
final class RunRetryPolicyTest extends TestCase
{
    public function testRetriesWithComputedBackoffWhenBudgetRemains(): void
    {
        $service = new RetryRunService();
        $output = $this->invoke($service, 'retries', (new JournalBuilder())->input('')->build());

        self::assertSame(1, $service->attempts(), 'closure executed once before the retry');
        self::assertSame(
            [MessageType::RunCommand, MessageType::Error],
            $this->frameTypes($output),
            'a retryable ErrorMessage closes the slice (no run-completion proposal)',
        );

        $nextRetryDelay = null;
        $behavior = null;
        $reader = new Reader($this->frameOfType($output, MessageType::Error));
        while (!$reader->atEnd()) {
            [$field, $wire] = $reader->readTag();
            switch ($field) {
                case 8:
                    $nextRetryDelay = $reader->readVarint();
                    break;
                case 9:
                    $behavior = $reader->readVarint();
                    break;
                default:
                    $reader->skip($wire);
            }
        }

        // initialInterval * factor^retryCount = 100 * 2^0 = 100.
        self::assertSame(100, $nextRetryDelay, 'backoff = initialInterval * factor^retryCount');
        self::assertNull($behavior, 'RETRY behavior (0) is omitted on the wire');
    }

    public function testGivesUpTerminallyWhenAttemptsExhausted(): void
    {
        $service = new RetryRunService();
        $output = $this->invoke($service, 'givesUp', (new JournalBuilder())->input('')->build());

        self::assertSame(1, $service->attempts());
        self::assertSame(
            [MessageType::RunCommand, MessageType::ProposeRunCompletion, MessageType::Suspension],
            $this->frameTypes($output),
            'an exhausted policy proposes a terminal run failure, then suspends',
        );

        $reader = new Reader($this->frameOfType($output, MessageType::ProposeRunCompletion));
        $failurePresent = false;
        while (!$reader->atEnd()) {
            [$field, $wire] = $reader->readTag();
            if ($field === 15) {
                $reader->readLengthDelimited();
                $failurePresent = true;
            } else {
                $reader->skip($wire);
            }
        }

        self::assertTrue($failurePresent, 'the run completion proposal carries a failure (field 15)');
    }

    private function invoke(RetryRunService $service, string $handler, string $journal): string
    {
        $endpoint = Endpoint::builder()->bind($service)->build();
        $request = new HttpRequest(
            'POST',
            "/invoke/RetryRunService/{$handler}",
            ['content-type' => ServiceProtocolVersion::V7->contentType()],
            $journal,
        );

        return (new RequestProcessor($endpoint))->process($request)->body;
    }

    /** @return list<MessageType|null> */
    private function frameTypes(string $output): array
    {
        return \array_map(static fn ($f) => $f->type(), MessageCodec::decodeAll($output));
    }

    private function frameOfType(string $output, MessageType $type): string
    {
        foreach (MessageCodec::decodeAll($output) as $frame) {
            if ($frame->type() === $type) {
                return $frame->payload;
            }
        }
        self::fail("No {$type->name} frame in response");
    }
}
