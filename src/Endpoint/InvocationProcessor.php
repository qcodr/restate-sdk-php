<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Endpoint;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qcodr\Restate\Sdk\Context\Clock;
use Qcodr\Restate\Sdk\Context\ContextRand;
use Qcodr\Restate\Sdk\Context\RestateContext;
use Qcodr\Restate\Sdk\Context\SystemClock;
use Qcodr\Restate\Sdk\Error\RetryableException;
use Qcodr\Restate\Sdk\Error\TerminalException;
use Qcodr\Restate\Sdk\Protocol\ErrorBehavior;
use Qcodr\Restate\Sdk\Protocol\Message\Failure;
use Qcodr\Restate\Sdk\Serde\JsonSerde;
use Qcodr\Restate\Sdk\Serde\Serde;
use Qcodr\Restate\Sdk\Service\HandlerDefinition;
use Qcodr\Restate\Sdk\Service\ServiceDefinition;
use Qcodr\Restate\Sdk\Vm\StateMachine;
use Qcodr\Restate\Sdk\Vm\SuspendException;
use Throwable;

/**
 * Drives a single invocation through the state machine: read the input, build the
 * context, run the user handler, and commit the result.
 *
 * The terminal paths map user outcomes onto the protocol:
 *  - normal return / {@see TerminalException} → an Output command + End (no retry);
 *  - {@see SuspendException} → the suspension already written by the VM (re-invoked later);
 *  - {@see RetryableException} → an Error message tuned with its retry delay / pause behavior;
 *  - any other throwable → an Error message (a retryable attempt failure).
 */
final class InvocationProcessor
{
    private readonly Serde $serde;
    private readonly Clock $clock;
    private readonly LoggerInterface $logger;

    /**
     * @param bool $debug when false (the default, i.e. production) the stacktrace sent
     *                    to the runtime is reduced to the exception class name so absolute
     *                    file paths, frame detail and message-borne secrets are not
     *                    disclosed over the wire; the full detail still goes to {@see $logger}.
     *                    Set true in development to forward the complete trace.
     */
    public function __construct(
        ?Serde $serde = null,
        ?Clock $clock = null,
        ?LoggerInterface $logger = null,
        private readonly bool $debug = false,
    ) {
        $this->serde = $serde ?? new JsonSerde();
        $this->clock = $clock ?? new SystemClock();
        $this->logger = $logger ?? new NullLogger();
    }

    public function process(ServiceDefinition $service, HandlerDefinition $handler, StateMachine $vm): void
    {
        $input = $vm->sysInput();
        $context = new RestateContext(
            $vm,
            $input,
            $this->serde,
            $this->clock,
            ContextRand::fromSeed($input->randomSeed),
            writable: !$handler->isShared(),
            logger: $this->logger,
            debug: $this->debug,
        );

        try {
            $arguments = [$context];
            if ($handler->hasInput) {
                $arguments[] = $this->serde->deserialize($input->body, $handler->inputType);
            }

            $result = $service->instance->{$handler->method}(...$arguments);

            $vm->sysWriteOutputSuccess($handler->hasOutput ? $this->serde->serialize($result) : '');
            $vm->sysEnd();
        } catch (SuspendException) {
            // The suspension message was already written by the state machine.
        } catch (TerminalException $e) {
            $vm->sysWriteOutputFailure(new Failure($e->statusCode(), $e->getMessage()));
            $vm->sysEnd();
        } catch (RetryableException $e) {
            $this->logger->warning('Invocation attempt failed (retryable): ' . $e->getMessage(), ['exception' => $e]);
            $vm->notifyError(
                TerminalException::DEFAULT_CODE,
                $e->getMessage(),
                $this->stacktraceFor($e),
                $e->retryDelayMillis,
                $e->pause ? ErrorBehavior::Pause : ErrorBehavior::Retry,
            );
        } catch (Throwable $e) {
            $this->logger->error('Invocation attempt failed: ' . $e->getMessage(), ['exception' => $e]);
            $vm->notifyError(TerminalException::DEFAULT_CODE, $e->getMessage(), $this->stacktraceFor($e));
        }
    }

    /**
     * The stacktrace forwarded to the runtime in the ErrorMessage stacktrace field. In
     * production (`debug = false`) this is only the exception class name — enough to
     * triage from runtime logs without leaking absolute file paths, frame detail, or
     * secrets a wrapped message might carry; the full string is logged locally instead.
     */
    private function stacktraceFor(Throwable $e): string
    {
        return $this->debug ? (string) $e : \get_class($e);
    }
}
