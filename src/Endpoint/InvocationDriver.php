<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Endpoint;

use Fiber;
use Psr\Log\LoggerInterface;
use Qcodr\Restate\Sdk\Context\Clock;
use Qcodr\Restate\Sdk\Protocol\ProtocolException;
use Qcodr\Restate\Sdk\Serde\Serde;
use Qcodr\Restate\Sdk\Service\HandlerDefinition;
use Qcodr\Restate\Sdk\Service\ServiceDefinition;
use Qcodr\Restate\Sdk\Vm\ParkSignal;
use Qcodr\Restate\Sdk\Vm\StateMachine;

/**
 * Owns a single invocation, bridging a {@see StateMachine} to a transport. It offers
 * the two ways the protocol can be served:
 *
 *  - {@see runRequestResponse}: the request/response path. The runtime sends the whole
 *    journal then EOF; the handler runs to completion or suspension in one slice, and
 *    the buffered frames are returned as one {@see HttpResponse}. This is the exact
 *    behavior the endpoint had inline, moved here verbatim, so the bytes are unchanged.
 *  - {@see driveStreaming}: bidirectional streaming. The handler runs inside a
 *    {@see \Fiber}; an unresolved await parks the fiber (it does not suspend the
 *    invocation), the driver feeds late completions/signals as they arrive on the open
 *    channel and resumes the fiber, and frames stream out as the handler produces them.
 *    EOF before resolution suspends gracefully.
 *
 * The two paths share one {@see InvocationProcessor}; the difference is entirely in
 * the {@see \Qcodr\Restate\Sdk\Vm\Suspender} and {@see \Qcodr\Restate\Sdk\Vm\OutputSink}
 * the caller wires into the {@see StateMachine} it hands in.
 */
final class InvocationDriver
{
    public const SDK_IDENTIFIER = 'restate-sdk-php/0.1.0';

    private const HEADER_SERVER = 'x-restate-server';
    private const HEADER_CONTENT_TYPE = 'content-type';

    private readonly InvocationProcessor $invocationProcessor;

    public function __construct(
        ?Serde $serde = null,
        ?Clock $clock = null,
        ?LoggerInterface $logger = null,
        bool $debug = false,
    ) {
        $this->invocationProcessor = new InvocationProcessor($serde, $clock, $logger, $debug);
    }

    /**
     * Runs one request/response slice: feed the whole body, run the handler, and frame
     * the buffered output into an {@see HttpResponse}. The {@see StateMachine} must be
     * built with the request/response defaults (the throwing suspender + buffering
     * sink), so an unresolved await writes a `SuspensionMessage` and unwinds.
     */
    public function runRequestResponse(
        StateMachine $vm,
        ServiceDefinition $service,
        HandlerDefinition $handler,
        string $body,
    ): HttpResponse {
        try {
            $vm->notifyInput($body);
            $vm->notifyInputClosed();
            if (!$vm->isReadyToExecute()) {
                return HttpResponse::of(500, 'Incomplete invocation stream', [self::HEADER_CONTENT_TYPE => 'text/plain']);
            }

            $this->invocationProcessor->process($service, $handler, $vm);
            $output = $vm->takeOutput();
        } catch (ProtocolException) {
            // Don't echo parser internals back to the caller (it is an oracle when the
            // endpoint is unauthenticated); the detail stays in the worker's logs.
            return HttpResponse::of(500, 'Malformed invocation stream', [self::HEADER_CONTENT_TYPE => 'text/plain']);
        }

        return HttpResponse::of(200, $output, [
            self::HEADER_CONTENT_TYPE => $vm->protocolVersion()->contentType(),
            self::HEADER_SERVER => self::SDK_IDENTIFIER,
        ]);
    }

    /**
     * Drives one invocation over a bidirectional {@see StreamTransport}. The
     * {@see StateMachine} must be built with the streaming wiring (a
     * {@see \Qcodr\Restate\Sdk\Vm\FiberSuspender} + {@see StreamingOutputSink}) so an
     * await parks the fiber and frames stream straight to $io.
     *
     * The handler runs inside a fiber the driver starts: starting it runs to the first
     * park or to termination. A park yields a {@see ParkSignal} carrying the await tree
     * and its readiness predicate; the driver keeps feeding inbound frames and resumes
     * the fiber only once the predicate holds, so the parked await runs straight on with
     * its result guaranteed present (no busy re-check). Because the streaming sink
     * already wrote every frame to $io (including the terminal Output/End/Error), there
     * is nothing to drain — the loop only ensures the channel is closed once the fiber
     * finishes or the runtime hangs up. A journal mismatch streams an Error and
     * terminates inside the fiber (the {@see InvocationProcessor} swallows the resulting
     * suspend), so it needs no special handling here; an EOF before the awaited result
     * arrives suspends gracefully.
     */
    public function driveStreaming(
        StateMachine $vm,
        ServiceDefinition $service,
        HandlerDefinition $handler,
        StreamTransport $io,
    ): void {
        // Read inbound bytes until the StartMessage + replayed journal are complete.
        while (!$vm->isReadyToExecute()) {
            $chunk = $io->read();
            if ($chunk === null) {
                // The runtime hung up before delivering a full journal; nothing to run.
                $io->close();

                return;
            }
            $vm->notifyInput($chunk);
        }

        $fiber = new Fiber(function () use ($service, $handler, $vm): void {
            $this->invocationProcessor->process($service, $handler, $vm);
        });

        // Run to the first park (a ParkSignal) or straight to a terminal frame, then drain
        // any await already satisfiable from journal-buffered notifications before we ever
        // block on the stream.
        $park = $this->drainResolved($fiber, $fiber->start());

        while (!$fiber->isTerminated()) {
            $chunk = $io->read();
            if ($chunk === null) {
                // EOF before the awaited result arrived: suspend gracefully so the
                // runtime re-invokes us later with the completion in the journal.
                $vm->notifyInputClosed();
                if ($park instanceof ParkSignal) {
                    $vm->writeSuspension($park->awaitTree);
                }

                break;
            }

            // Routes late completions/signals (and skips ack/control frames), then resumes
            // the fiber for every await this chunk satisfies — not just one — before
            // blocking on the next read. A single chunk can carry several notifications
            // (batched completions, or a completion plus the cancel), and each resumed
            // await may run straight on to the next whose result is already present; a
            // frame that does not make the current await resolvable still does not wake it.
            $vm->notifyInput($chunk);
            $park = $this->drainResolved($fiber, $park);
        }

        $io->close();
    }

    /**
     * Resumes the fiber while the current park's awaited result is already present, so a
     * single inbound chunk drives every await it satisfies before the driver blocks on the
     * next read. Returns the park the fiber is left on, or null once it terminates.
     *
     * Each iteration advances `$park = $fiber->resume()` and re-tests the new park, so a
     * resumed await either returns, throws (terminating the fiber), or re-parks on a
     * still-unresolved await whose predicate is false — the loop always makes progress.
     *
     * `$park` is typed `mixed` because {@see \Fiber::start()}/{@see \Fiber::resume()} return
     * mixed; the suspender only ever yields a {@see ParkSignal} and the fiber body returns
     * void, so in practice the value is always `ParkSignal|null`.
     *
     * @param Fiber<mixed, mixed, mixed, mixed> $fiber
     */
    private function drainResolved(Fiber $fiber, mixed $park): mixed
    {
        while ($park instanceof ParkSignal && ($park->isResolved)()) {
            $park = $fiber->resume();
        }

        return $park;
    }
}
