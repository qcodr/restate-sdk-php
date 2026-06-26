<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Context;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Endpoint\Endpoint;
use Qcodr\Restate\Sdk\Endpoint\HttpRequest;
use Qcodr\Restate\Sdk\Endpoint\RequestProcessor;
use Qcodr\Restate\Sdk\Protocol\Message\Value;
use Qcodr\Restate\Sdk\Protocol\MessageCodec;
use Qcodr\Restate\Sdk\Protocol\MessageType;
use Qcodr\Restate\Sdk\Protocol\Protobuf\Reader;
use Qcodr\Restate\Sdk\Protocol\ServiceProtocolVersion;
use Qcodr\Restate\Sdk\Tests\Support\Fixtures\RaceService;
use Qcodr\Restate\Sdk\Tests\Support\JournalBuilder;

/**
 * Verifies the FIRST_COMPLETED combinator: when one of two concurrent calls is
 * already resolved in the journal, select() returns its index and value without
 * suspending.
 */
final class SelectTest extends TestCase
{
    public function testSelectReturnsTheReadyFuture(): void
    {
        // Two calls allocate completion ids (1,2) and (3,4); the journal resolves
        // only the second call's result (id 4).
        $journal = (new JournalBuilder())
            ->input('')
            ->command(MessageType::CallCommand)
            ->command(MessageType::CallCommand)
            ->callCompletion(4, '"B"')
            ->build();

        $endpoint = Endpoint::builder()->bind(new RaceService())->build();
        $request = new HttpRequest(
            'POST',
            '/invoke/RaceService/race',
            ['content-type' => ServiceProtocolVersion::V7->contentType()],
            $journal,
        );

        $output = (new RequestProcessor($endpoint))->process($request)->body;

        $frames = MessageCodec::decodeAll($output);
        self::assertSame(
            [MessageType::OutputCommand, MessageType::End],
            \array_map(static fn ($f) => $f->type(), $frames),
        );

        $reader = new Reader($frames[0]->payload);
        $reader->readTag();
        self::assertSame('"winner:1:B"', Value::decode($reader->readLengthDelimited())->content);
    }
}
