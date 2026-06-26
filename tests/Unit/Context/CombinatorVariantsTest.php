<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Context;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Endpoint\Endpoint;
use Qcodr\Restate\Sdk\Endpoint\HttpRequest;
use Qcodr\Restate\Sdk\Endpoint\RequestProcessor;
use Qcodr\Restate\Sdk\Protocol\Message\Failure;
use Qcodr\Restate\Sdk\Protocol\Message\Value;
use Qcodr\Restate\Sdk\Protocol\MessageCodec;
use Qcodr\Restate\Sdk\Protocol\MessageType;
use Qcodr\Restate\Sdk\Protocol\Protobuf\Reader;
use Qcodr\Restate\Sdk\Protocol\ServiceProtocolVersion;
use Qcodr\Restate\Sdk\Tests\Support\Fixtures\CombinatorVariantsService;
use Qcodr\Restate\Sdk\Tests\Support\JournalBuilder;

/**
 * Verifies the FIRST_SUCCEEDED_OR_ALL_FAILED (Promise.any) and
 * ALL_SUCCEEDED_OR_FIRST_FAILED (Promise.all) combinator variants: awaitAny skips a
 * ready-but-failed future and returns the first successful result, while
 * awaitAllSucceeded short-circuits and rethrows on the first failed future.
 */
final class CombinatorVariantsTest extends TestCase
{
    public function testAwaitAnyReturnsFirstSuccessfulResultSkippingAnEarlierFailure(): void
    {
        // Two calls allocate completion ids (1,2) and (3,4); the journal fails the
        // first call's result (id 2) and succeeds the second (id 4).
        $journal = (new JournalBuilder())
            ->input('')
            ->command(MessageType::CallCommand)
            ->command(MessageType::CallCommand)
            ->failedCallCompletion(2, 'first call failed')
            ->callCompletion(4, '"B"')
            ->build();

        $output = $this->invoke('any', $journal);

        $frames = MessageCodec::decodeAll($output);
        self::assertSame(
            [MessageType::OutputCommand, MessageType::End],
            \array_map(static fn ($f) => $f->type(), $frames),
        );

        $reader = new Reader($frames[0]->payload);
        $reader->readTag();
        self::assertSame('"B"', Value::decode($reader->readLengthDelimited())->content);
    }

    public function testAwaitAllSucceededRethrowsOnAFailedFuture(): void
    {
        $journal = (new JournalBuilder())
            ->input('')
            ->command(MessageType::CallCommand)
            ->command(MessageType::CallCommand)
            ->failedCallCompletion(2, 'boom')
            ->callCompletion(4, '"B"')
            ->build();

        $output = $this->invoke('allSucceeded', $journal);

        $types = \array_map(static fn ($f) => $f->type(), MessageCodec::decodeAll($output));
        self::assertContains(MessageType::OutputCommand, $types);
        self::assertContains(MessageType::End, $types);
        self::assertNotContains(
            MessageType::Suspension,
            $types,
            'a failed future short-circuits instead of suspending',
        );

        $reader = new Reader($this->frameOfType($output, MessageType::OutputCommand));
        [$field] = $reader->readTag();
        self::assertSame(15, $field, 'output is a failure result');
        $failure = Failure::decode($reader->readLengthDelimited());
        self::assertSame(500, $failure->code);
        self::assertSame('boom', $failure->message);
    }

    private function invoke(string $handler, string $journal): string
    {
        $endpoint = Endpoint::builder()->bind(new CombinatorVariantsService())->build();
        $request = new HttpRequest(
            'POST',
            "/invoke/CombinatorVariantsService/{$handler}",
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
