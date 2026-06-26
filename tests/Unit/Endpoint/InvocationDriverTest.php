<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Tests\Unit\Endpoint;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Sdk\Endpoint\Endpoint;
use Qcodr\Restate\Sdk\Endpoint\InvocationDriver;
use Qcodr\Restate\Sdk\Endpoint\StreamingOutputSink;
use Qcodr\Restate\Sdk\Error\CancelledException;
use Qcodr\Restate\Sdk\Protocol\Frame;
use Qcodr\Restate\Sdk\Protocol\Message\Failure;
use Qcodr\Restate\Sdk\Protocol\Message\Value;
use Qcodr\Restate\Sdk\Protocol\MessageCodec;
use Qcodr\Restate\Sdk\Protocol\MessageType;
use Qcodr\Restate\Sdk\Protocol\Protobuf\Reader;
use Qcodr\Restate\Sdk\Protocol\ServiceProtocolVersion;
use Qcodr\Restate\Sdk\Service\HandlerDefinition;
use Qcodr\Restate\Sdk\Service\ServiceDefinition;
use Qcodr\Restate\Sdk\Tests\Support\BufferedStreamTransport;
use Qcodr\Restate\Sdk\Tests\Support\Fixtures\CallOptionsService;
use Qcodr\Restate\Sdk\Tests\Support\Fixtures\CancellationService;
use Qcodr\Restate\Sdk\Tests\Support\Fixtures\Greeter;
use Qcodr\Restate\Sdk\Tests\Support\Fixtures\RunService;
use Qcodr\Restate\Sdk\Tests\Support\JournalBuilder;
use Qcodr\Restate\Sdk\Vm\FiberSuspender;
use Qcodr\Restate\Sdk\Vm\StateMachine;

/**
 * Proves the streaming invocation driver over a network-free
 * {@see BufferedStreamTransport}: a parked await does NOT emit a `SuspensionMessage`
 * but waits for the runtime to stream a result; commands stream out as the handler
 * produces them; cancellation and ack-requiring runs are handled inline; and an EOF
 * before resolution suspends gracefully.
 *
 * The request/response parity of the same processor lives in {@see RequestProcessorTest}
 * and friends (the default throwing suspender + buffering sink); this suite only
 * exercises {@see InvocationDriver::driveStreaming}.
 */
final class InvocationDriverTest extends TestCase
{
    /**
     * Drives one streaming invocation of $handlerName on the bound $serviceInstance,
     * feeding $inbound chunks (first the journal, then late notifications).
     *
     * @param list<string> $inbound
     */
    private function drive(object $serviceInstance, string $serviceName, string $handlerName, array $inbound): BufferedStreamTransport
    {
        $endpoint = Endpoint::builder()->bind($serviceInstance)->build();
        $service = $endpoint->service($serviceName);
        self::assertInstanceOf(ServiceDefinition::class, $service);
        $handler = $service->handler($handlerName);
        self::assertInstanceOf(HandlerDefinition::class, $handler);

        $transport = new BufferedStreamTransport($inbound);
        $vm = new StateMachine(ServiceProtocolVersion::V7, new FiberSuspender(), new StreamingOutputSink($transport));

        (new InvocationDriver())->driveStreaming($vm, $service, $handler, $transport);

        return $transport;
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
        self::fail('No OutputCommand in streamed output');
    }

    private function failure(string $output): Failure
    {
        foreach (MessageCodec::decodeAll($output) as $frame) {
            if ($frame->type() === MessageType::OutputCommand) {
                $reader = new Reader($frame->payload);
                [$field] = $reader->readTag();
                self::assertSame(15, $field, 'output carries a failure result');

                return Failure::decode($reader->readLengthDelimited());
            }
        }
        self::fail('No OutputCommand in streamed output');
    }

    public function testLateCompletionResolvesParkedCallWithoutSuspending(): void
    {
        // The call's invocation-id lands on completion id 1; it is streamed in only
        // after the handler has parked, so the CallCommand must already be on the wire.
        $transport = $this->drive(
            new CallOptionsService(),
            'CallOptionsService',
            'callAndReturnInvocationId',
            [
                (new JournalBuilder())->input('')->build(),
                (new JournalBuilder())->invocationIdCompletion(1, 'inv-xyz')->frames(),
            ],
        );

        $output = $transport->written();
        self::assertSame([MessageType::CallCommand, MessageType::OutputCommand, MessageType::End], $this->frameTypes($output));
        self::assertNotContains(MessageType::Suspension, $this->frameTypes($output), 'a parked await must not write a suspension');
        self::assertSame('"inv-xyz"', $this->successValue($output));
        self::assertTrue($transport->isClosed(), 'the driver closes the channel after the terminal frame');

        // Read #1 is the completion read; the CallCommand was already written by then.
        self::assertSame([MessageType::CallCommand], $this->frameTypes($transport->outputAtRead(1)));
    }

    public function testSpuriousFrameDoesNotResumeParkedHandlerPrematurely(): void
    {
        // The handler parks awaiting the call's invocation id (completion id 1). A frame
        // that resolves only an unrelated id — here the never-awaited call result, id 2 —
        // must NOT wake the handler: the predicate stays false, so the driver keeps
        // reading until the awaited completion arrives.
        $transport = $this->drive(
            new CallOptionsService(),
            'CallOptionsService',
            'callAndReturnInvocationId',
            [
                (new JournalBuilder())->input('')->build(),
                // Spurious: completes id 2 (the unawaited result), not the awaited id 1.
                (new JournalBuilder())->callCompletion(2, '"unawaited"')->frames(),
                // The awaited invocation id finally arrives and resolves the parked await.
                (new JournalBuilder())->invocationIdCompletion(1, 'inv-xyz')->frames(),
            ],
        );

        $output = $transport->written();
        self::assertSame([MessageType::CallCommand, MessageType::OutputCommand, MessageType::End], $this->frameTypes($output));
        self::assertNotContains(MessageType::Suspension, $this->frameTypes($output));
        // The awaited id (not the spurious frame's value) is returned: had the spurious
        // frame resumed the now straight-line await, it would have observed an absent
        // completion and failed instead of returning this value.
        self::assertSame('"inv-xyz"', $this->successValue($output));

        // The spurious frame (read #1) and the resolving frame (read #2) were both
        // consumed while only the CallCommand had been written: the handler stayed parked
        // across the spurious frame and emitted its terminal output only after read #2.
        self::assertSame([MessageType::CallCommand], $this->frameTypes($transport->outputAtRead(1)));
        self::assertSame([MessageType::CallCommand], $this->frameTypes($transport->outputAtRead(2)));
    }

    public function testPromptCancelTurnsParkedAwaitIntoTerminal409(): void
    {
        $transport = $this->drive(
            new CancellationService(),
            'CancellationService',
            'awaitThenSleep',
            [
                (new JournalBuilder())->input('')->build(),
                (new JournalBuilder())->cancelSignal()->frames(),
            ],
        );

        $output = $transport->written();
        self::assertSame([MessageType::SleepCommand, MessageType::OutputCommand, MessageType::End], $this->frameTypes($output));
        self::assertNotContains(MessageType::Suspension, $this->frameTypes($output));

        $failure = $this->failure($output);
        self::assertSame(CancelledException::CODE, $failure->code, 'a cancelled await fails with HTTP 409');
        self::assertSame('cancelled', $failure->message);
    }

    public function testRunWithAckResolvesOnRunCompletion(): void
    {
        $service = new RunService();
        $endpoint = Endpoint::builder()->bind($service)->build();
        $serviceDefinition = $endpoint->service('RunService');
        self::assertInstanceOf(ServiceDefinition::class, $serviceDefinition);
        $handler = $serviceDefinition->handler('process');
        self::assertInstanceOf(HandlerDefinition::class, $handler);

        $transport = new BufferedStreamTransport([
            (new JournalBuilder())->input('')->build(),
            // The ack control frame is read and ignored; the value arrives separately.
            (new JournalBuilder())->proposeRunCompletionAck(1)->frames(),
            (new JournalBuilder())->runCompletion(1, '"effect-result"')->frames(),
        ]);
        $vm = new StateMachine(ServiceProtocolVersion::V7, new FiberSuspender(), new StreamingOutputSink($transport));

        (new InvocationDriver())->driveStreaming($vm, $serviceDefinition, $handler, $transport);

        $output = $transport->written();
        self::assertSame(1, $service->runs(), 'the side effect executed exactly once');
        self::assertSame([
            MessageType::RunCommand,
            MessageType::ProposeRunCompletion,
            MessageType::OutputCommand,
            MessageType::End,
        ], $this->frameTypes($output));
        self::assertNotContains(MessageType::Suspension, $this->frameTypes($output));
        self::assertSame('"effect-result"', $this->successValue($output));

        // The propose frame requested an ack (the only frame that sets the flag).
        $proposeFrame = $this->frameOfType($output, MessageType::ProposeRunCompletion);
        self::assertTrue($proposeFrame->requestedAck, 'ProposeRunCompletion sets the REQUESTED_ACK flag');
    }

    public function testHandlerCompletingWithoutAwaitingEmitsOneShotOutputAndEnd(): void
    {
        $transport = $this->drive(
            new Greeter(),
            'Greeter',
            'greet',
            [(new JournalBuilder())->input('"world"')->build()],
        );

        $output = $transport->written();
        self::assertSame([MessageType::OutputCommand, MessageType::End], $this->frameTypes($output));
        self::assertSame('"Greetings world"', $this->successValue($output));
        self::assertTrue($transport->isClosed());

        // The handler never parked: only the single journal read happened (read #0),
        // so the resume loop body never ran.
        self::assertSame('', $transport->outputAtRead(1), 'no second read occurred — the loop body never ran');
    }

    public function testEofWhileParkedSuspendsGracefully(): void
    {
        // No notification chunk after the journal: the runtime hangs up while the
        // handler is parked on the never-arriving sleep timer.
        $transport = $this->drive(
            new CancellationService(),
            'CancellationService',
            'awaitThenSleep',
            [(new JournalBuilder())->input('')->build()],
        );

        $output = $transport->written();
        self::assertSame([MessageType::SleepCommand, MessageType::Suspension], $this->frameTypes($output));
        self::assertNotContains(MessageType::OutputCommand, $this->frameTypes($output), 'no terminal output on a graceful suspend');
        self::assertTrue($transport->isClosed());
    }

    private function frameOfType(string $output, MessageType $type): Frame
    {
        foreach (MessageCodec::decodeAll($output) as $frame) {
            if ($frame->type() === $type) {
                return $frame;
            }
        }
        self::fail("No {$type->name} frame in streamed output");
    }
}
