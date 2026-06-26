<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Endpoint;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Endpoint\Endpoint;
use Qcodr\Restate\Sdk\Endpoint\HttpRequest;
use Qcodr\Restate\Sdk\Endpoint\RequestProcessor;
use Qcodr\Restate\Sdk\Error\CancelledException;
use Qcodr\Restate\Sdk\Protocol\ErrorBehavior;
use Qcodr\Restate\Sdk\Protocol\Message\Failure;
use Qcodr\Restate\Sdk\Protocol\Message\SendSignalCommand;
use Qcodr\Restate\Sdk\Protocol\MessageCodec;
use Qcodr\Restate\Sdk\Protocol\MessageType;
use Qcodr\Restate\Sdk\Protocol\Protobuf\Reader;
use Qcodr\Restate\Sdk\Protocol\ServiceProtocolVersion;
use Qcodr\Restate\Sdk\Tests\Support\Fixtures\CancellationService;
use Qcodr\Restate\Sdk\Tests\Support\JournalBuilder;

/**
 * Covers durable-error tuning and cancellation end-to-end: a pausing
 * {@see \Qcodr\Restate\Sdk\Error\RetryableException} surfaces as an ErrorMessage with
 * behavior=Pause; {@see \Qcodr\Restate\Sdk\Context\Context::cancel} emits a CANCEL
 * SendSignalCommand; and a delivered CANCEL signal turns a pending await into a
 * terminal 409 ({@see CancelledException}).
 */
final class CancellationTest extends TestCase
{
    public function testRetryableExceptionWithPauseEmitsPauseBehavior(): void
    {
        $journal = (new JournalBuilder())->input('')->build();
        $output = $this->invoke('failPaused', $journal);

        $error = $this->frameOfType($output, MessageType::Error);

        $behavior = null;
        $reader = new Reader($error);
        while (!$reader->atEnd()) {
            [$field, $wire] = $reader->readTag();
            if ($field === 9) {
                $behavior = $reader->readVarint();
            } else {
                $reader->skip($wire);
            }
        }

        self::assertSame(ErrorBehavior::Pause->value, $behavior, 'ErrorMessage carries behavior=Pause');
    }

    public function testCancelEmitsSendSignalCommandWithCancelIdx(): void
    {
        $journal = (new JournalBuilder())->input('')->build();
        $output = $this->invoke('cancelOther', $journal);

        $signal = $this->frameOfType($output, MessageType::SendSignalCommand);

        $idx = null;
        $target = null;
        $reader = new Reader($signal);
        while (!$reader->atEnd()) {
            [$field, $wire] = $reader->readTag();
            switch ($field) {
                case 1:
                    $target = $reader->readLengthDelimited();
                    break;
                case 2:
                    $idx = $reader->readVarint();
                    break;
                default:
                    $reader->skip($wire);
            }
        }

        self::assertSame('inv-target-99', $target);
        self::assertSame(SendSignalCommand::CANCEL_SIGNAL_INDEX, $idx, 'cancel uses built-in signal idx 1');
    }

    public function testDeliveredCancelSignalYields409TerminalOutput(): void
    {
        $journal = (new JournalBuilder())
            ->input('')
            ->cancelSignal()
            ->build();

        $output = $this->invoke('awaitThenSleep', $journal);

        $types = \array_map(static fn ($f) => $f->type(), MessageCodec::decodeAll($output));
        self::assertContains(MessageType::OutputCommand, $types);
        self::assertContains(MessageType::End, $types);
        self::assertNotContains(MessageType::Suspension, $types, 'a cancelled await fails instead of suspending');

        $reader = new Reader($this->frameOfType($output, MessageType::OutputCommand));
        [$field] = $reader->readTag();
        self::assertSame(15, $field, 'output is a failure result');
        $failure = Failure::decode($reader->readLengthDelimited());

        self::assertSame(CancelledException::CODE, $failure->code, 'terminal failure carries HTTP 409');
        self::assertSame('cancelled', $failure->message);
    }

    private function invoke(string $handler, string $journal): string
    {
        $endpoint = Endpoint::builder()->bind(new CancellationService())->build();
        $request = new HttpRequest(
            'POST',
            "/invoke/CancellationService/{$handler}",
            ['content-type' => ServiceProtocolVersion::V7->contentType()],
            $journal,
        );

        return (new RequestProcessor($endpoint))->process($request)->body;
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
