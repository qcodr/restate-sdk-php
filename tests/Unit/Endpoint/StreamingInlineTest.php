<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Endpoint;

use Closure;
use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Endpoint\Endpoint;
use Qcodr\Restate\Sdk\Endpoint\HttpRequest;
use Qcodr\Restate\Sdk\Endpoint\ProtocolMode;
use Qcodr\Restate\Sdk\Endpoint\RequestProcessor;
use Qcodr\Restate\Sdk\Endpoint\StreamingInlineResult;
use Qcodr\Restate\Sdk\Endpoint\StreamingInvocation;
use Qcodr\Restate\Sdk\Endpoint\StreamingOutputSink;
use Qcodr\Restate\Sdk\Protocol\Message\Value;
use Qcodr\Restate\Sdk\Protocol\MessageCodec;
use Qcodr\Restate\Sdk\Protocol\MessageType;
use Qcodr\Restate\Sdk\Protocol\Protobuf\Reader;
use Qcodr\Restate\Sdk\Protocol\ServiceProtocolVersion;
use Qcodr\Restate\Sdk\Tests\Support\BufferedStreamTransport;
use Qcodr\Restate\Sdk\Tests\Support\Fixtures\CallOptionsService;
use Qcodr\Restate\Sdk\Tests\Support\Fixtures\CancellationService;
use Qcodr\Restate\Sdk\Tests\Support\Fixtures\Greeter;
use Qcodr\Restate\Sdk\Tests\Support\JournalBuilder;

/**
 * The bidi streaming **fast path** ({@see RequestProcessor::tryDriveStreamingInline} +
 * {@see RequestProcessor::continueStreamingFromPark}) that the live server now uses in
 * place of {@see RequestProcessor::driveStreaming}: a handler that completes without
 * parking is run inline and its whole output returned in one buffer (no `async()`, no
 * outbound Queue); a handler that parks hands back the pre-park preamble plus the live
 * fiber, and the continuation streams the rest exactly as `driveStreaming` would.
 *
 * These tests assert the inline path is behaviour-equivalent to the buffered
 * {@see InvocationDriverTest} / {@see RequestProcessorStreamingTest} cases: same frames,
 * same values, same EOF-while-parked suspension.
 */
final class StreamingInlineTest extends TestCase
{
    /**
     * @return array{RequestProcessor, StreamingInvocation}
     */
    private function resolve(object $service, string $serviceName, string $handler): array
    {
        $endpoint = Endpoint::builder()->bind($service)->build();
        $processor = new RequestProcessor($endpoint, transportCapability: ProtocolMode::BidiStream);
        $resolved = $processor->resolveStreamingInvoke(new HttpRequest(
            'POST',
            "/invoke/{$serviceName}/{$handler}",
            ['content-type' => ServiceProtocolVersion::V7->contentType()],
            '',
        ));
        self::assertInstanceOf(StreamingInvocation::class, $resolved);

        return [$processor, $resolved];
    }

    /**
     * A reader closure that yields each chunk once, then null at EOF — the inline path's
     * phase-1 journal source.
     *
     * @param list<string> $chunks
     *
     * @return Closure(): ?string
     */
    private function chunkReader(array $chunks): Closure
    {
        $i = 0;

        return static function () use ($chunks, &$i): ?string {
            return $chunks[$i++] ?? null;
        };
    }

    /** @return list<MessageType|null> */
    private function frameTypes(string $output): array
    {
        return \array_map(static fn ($frame) => $frame->type(), MessageCodec::decodeAll($output));
    }

    private function successValue(string $output): string
    {
        foreach (MessageCodec::decodeAll($output) as $frame) {
            if ($frame->type() === MessageType::OutputCommand) {
                $reader = new Reader($frame->payload);
                [$field] = $reader->readTag();
                self::assertSame(14, $field, 'output carries a success value');

                return Value::decode($reader->readLengthDelimited())->content;
            }
        }
        self::fail('No OutputCommand in output');
    }

    public function testCompletedHandlerRunsInlineAndBuffersTheWholeOutput(): void
    {
        // A non-parking handler completes in the first slice: tryDriveStreamingInline
        // reports completed and returns the full Output/End body, with no fiber/VM/sink to
        // continue — the live server returns this as a single ReadableBuffer.
        [$processor, $target] = $this->resolve(new Greeter(), 'Greeter', 'greet');

        $result = $processor->tryDriveStreamingInline(
            $target,
            $this->chunkReader([(new JournalBuilder())->input('"world"')->build()]),
        );

        self::assertTrue($result->completed);
        self::assertNull($result->vm);
        self::assertNull($result->handlerFiber);
        self::assertNull($result->switchSink);
        self::assertSame([MessageType::OutputCommand, MessageType::End], $this->frameTypes($result->output));
        self::assertSame('"Greetings world"', $this->successValue($result->output));
    }

    public function testParkedHandlerStreamsPreambleThenResolvesOnLateCompletion(): void
    {
        // The handler parks awaiting the call's invocation id (completion 1). The inline
        // phase buffers the pre-park preamble (CallCommand + AwaitingOn); the continuation
        // then streams the terminal Output/End once the completion arrives — same frames as
        // the buffered driver, no Suspension.
        [$processor, $target] = $this->resolve(new CallOptionsService(), 'CallOptionsService', 'callAndReturnInvocationId');

        $result = $processor->tryDriveStreamingInline(
            $target,
            $this->chunkReader([(new JournalBuilder())->input('')->build()]),
        );

        self::assertFalse($result->completed);
        self::assertNotNull($result->switchSink);
        self::assertNotNull($result->vm);
        self::assertNotNull($result->handlerFiber);
        self::assertSame([MessageType::CallCommand, MessageType::AwaitingOn], $this->frameTypes($result->output));

        // Wire the sink to the transport and feed the late completion, as the server does.
        $transport = new BufferedStreamTransport([(new JournalBuilder())->invocationIdCompletion(1, 'inv-xyz')->frames()]);
        $result->switchSink->switchToDownstream(new StreamingOutputSink($transport));
        $processor->continueStreamingFromPark($result, $transport);

        self::assertSame([MessageType::OutputCommand, MessageType::End], $this->frameTypes($transport->written()));
        self::assertNotContains(MessageType::Suspension, $this->frameTypes($transport->written()));
        self::assertSame('"inv-xyz"', $this->successValue($transport->written()));
        self::assertTrue($transport->isClosed());
    }

    public function testInlineCompletesEmptyWhenJournalEndsBeforeReady(): void
    {
        // The runtime hangs up before sending a full journal: tryStartInline reads null
        // while the VM is not yet ready to execute, so the inline attempt completes with no
        // output (the live server then returns an empty body). Covers the EOF-before-journal
        // branch — the handler never runs.
        [$processor, $target] = $this->resolve(new Greeter(), 'Greeter', 'greet');

        $result = $processor->tryDriveStreamingInline($target, $this->chunkReader([])); // immediate EOF

        self::assertTrue($result->completed);
        self::assertSame('', $result->output);
        self::assertNull($result->handlerFiber);
        self::assertNull($result->vm);
    }

    public function testContinueFromParkClosesWhenResultCarriesNoFiber(): void
    {
        // Defensive guard: a parked result always carries a VM + fiber, but the fields are
        // nullable; if mis-constructed without them the continuation must close the channel
        // rather than dereference null. This exercises that guard directly.
        $processor = new RequestProcessor(
            Endpoint::builder()->bind(new Greeter())->build(),
            transportCapability: ProtocolMode::BidiStream,
        );
        $result = new StreamingInlineResult(
            completed: false,
            output: '',
            vm: null,
            handlerFiber: null,
            park: null,
            switchSink: null,
        );
        $transport = new BufferedStreamTransport([]);

        $processor->continueStreamingFromPark($result, $transport);

        self::assertTrue($transport->isClosed());
        self::assertSame('', $transport->written());
    }

    public function testParkedHandlerSuspendsGracefullyOnEofDuringContinuation(): void
    {
        // The handler parks on a sleep timer; the runtime then hangs up (EOF) before the
        // timer fires. The continuation must write exactly one SuspensionMessage so the
        // runtime re-invokes later — the EOF-while-parked invariant, preserved by the
        // inline split.
        [$processor, $target] = $this->resolve(new CancellationService(), 'CancellationService', 'awaitThenSleep');

        $result = $processor->tryDriveStreamingInline(
            $target,
            $this->chunkReader([(new JournalBuilder())->input('')->build()]),
        );

        self::assertFalse($result->completed);
        self::assertNotNull($result->switchSink);
        self::assertSame([MessageType::SleepCommand, MessageType::AwaitingOn], $this->frameTypes($result->output));

        $transport = new BufferedStreamTransport([]); // immediate EOF
        $result->switchSink->switchToDownstream(new StreamingOutputSink($transport));
        $processor->continueStreamingFromPark($result, $transport);

        self::assertSame([MessageType::Suspension], $this->frameTypes($transport->written()));
        self::assertNotContains(MessageType::OutputCommand, $this->frameTypes($transport->written()));
        self::assertTrue($transport->isClosed());
    }
}
