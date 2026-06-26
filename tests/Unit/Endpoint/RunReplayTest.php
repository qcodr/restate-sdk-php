<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Endpoint;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Endpoint\Endpoint;
use Qcodr\Restate\Sdk\Endpoint\HttpRequest;
use Qcodr\Restate\Sdk\Endpoint\RequestProcessor;
use Qcodr\Restate\Sdk\Protocol\Message\Value;
use Qcodr\Restate\Sdk\Protocol\MessageCodec;
use Qcodr\Restate\Sdk\Protocol\MessageType;
use Qcodr\Restate\Sdk\Protocol\Protobuf\Reader;
use Qcodr\Restate\Sdk\Protocol\ServiceProtocolVersion;
use Qcodr\Restate\Sdk\Tests\Support\Fixtures\RunService;
use Qcodr\Restate\Sdk\Tests\Support\JournalBuilder;

/**
 * Exercises the durable side-effect lifecycle across two invocation slices:
 * the first executes the closure and suspends after proposing the result; the
 * second replays the stored result without re-running the closure.
 */
final class RunReplayTest extends TestCase
{
    private function process(RunService $service, string $journal): string
    {
        $endpoint = Endpoint::builder()->bind($service)->build();
        $request = new HttpRequest(
            'POST',
            '/invoke/RunService/process',
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

    public function testFirstSliceExecutesClosureProposesAndSuspends(): void
    {
        $service = new RunService();
        $output = $this->process($service, (new JournalBuilder())->input('')->build());

        self::assertSame(1, $service->runs(), 'closure executes during processing');
        self::assertSame([
            MessageType::RunCommand,
            MessageType::ProposeRunCompletion,
            MessageType::Suspension,
        ], $this->frameTypes($output));
    }

    public function testReplaySliceReturnsStoredResultWithoutRerunning(): void
    {
        $service = new RunService();
        $journal = (new JournalBuilder())
            ->input('')
            ->command(MessageType::RunCommand)
            ->runCompletion(1, '"effect-result"')
            ->build();

        $output = $this->process($service, $journal);

        self::assertSame(0, $service->runs(), 'closure must not run on replay');
        self::assertSame([MessageType::OutputCommand, MessageType::End], $this->frameTypes($output));

        $reader = new Reader(MessageCodec::decodeAll($output)[0]->payload);
        $reader->readTag();
        self::assertSame('"effect-result"', Value::decode($reader->readLengthDelimited())->content);
    }
}
