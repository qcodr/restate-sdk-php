<?php

declare(strict_types=1);

namespace Restate\Conformance;

use ReflectionProperty;
use Restate\Sdk\Context\Awakeable;
use Restate\Sdk\Context\DurableFuture;
use Restate\Sdk\Context\ObjectContext;
use Restate\Sdk\Context\SharedObjectContext;
use Restate\Sdk\Error\TerminalException;
use Restate\Sdk\Service\Attribute\Handler;
use Restate\Sdk\Service\Attribute\Shared;
use Restate\Sdk\Service\Attribute\VirtualObject;

/**
 * Conformance `VirtualObjectCommandInterpreter` Virtual Object.
 *
 * Interprets a small command language to drive awakeables, timers, and side effects,
 * recording each command's result into the "results" list. Mirrors the Rust
 * test-service `virtual_object_command_interpreter.rs`.
 *
 * State layout:
 *   - "awk-{awakeableKey}"  the awakeable id registered for that key (string)
 *   - "results"             the ordered list<string> of per-command results
 *
 * Limitations (vs. the contract) — both apply only to the `awaitAny` /
 * `awaitAnySuccessful` combinators, whose conformance tests are excluded (they are
 * unsupported in the Rust SDK too):
 *   1. A `runThrowTerminalException` awaitable cannot be raced concurrently, because
 *      the PHP SDK's run() is blocking and exposes no future handle; it raises a
 *      terminal failure if used inside those combinators. It IS supported in `awaitOne`.
 *   2. A `sleep` awaitable yields "" instead of "sleep" inside those combinators,
 *      because a bare durable timer future resolves to null with no way to remap it.
 *      In `awaitOne`, `sleep` correctly yields "sleep".
 */
#[VirtualObject(name: 'VirtualObjectCommandInterpreter')]
final class VirtualObjectCommandInterpreter
{
    private const RESULTS = 'results';

    /**
     * @param array{commands?: list<array<string, mixed>>} $request
     */
    #[Handler]
    public function interpretCommands(ObjectContext $ctx, array $request): string
    {
        $commands = $request['commands'] ?? [];
        $lastResult = '';

        foreach ($commands as $command) {
            $type = $command['type'] ?? '';

            switch ($type) {
                case 'awaitOne':
                    $lastResult = $this->runAwaitableCommand($ctx, $command['command']);
                    break;

                case 'awaitAny':
                    // First awaitable to COMPLETE (success or failure) wins.
                    [, $value] = $ctx->select(...$this->awaitableFutures($ctx, $command['commands']));
                    $lastResult = self::asString($value);
                    break;

                case 'awaitAnySuccessful':
                    // First awaitable to SUCCEED wins.
                    $lastResult = self::asString(
                        $ctx->awaitAny(...$this->awaitableFutures($ctx, $command['commands'])),
                    );
                    break;

                case 'awaitAwakeableOrTimeout':
                    $awakeable = $ctx->awakeable();
                    $ctx->set(self::awkKey((string) $command['awakeableKey']), $awakeable->id());

                    $timeoutSeconds = ((float) $command['timeoutMillis']) / 1000;
                    [$index, $value] = $ctx->select(
                        self::awakeableFuture($awakeable),
                        $ctx->timer($timeoutSeconds),
                    );

                    if ($index === 1) {
                        throw new TerminalException('await-timeout');
                    }

                    $lastResult = self::asString($value);
                    break;

                case 'resolveAwakeable':
                    $id = $ctx->get(self::awkKey((string) $command['awakeableKey']));
                    if (!\is_string($id)) {
                        throw new TerminalException('Awakeable is not registered yet');
                    }
                    $ctx->resolveAwakeable($id, $command['value'] ?? '');
                    $lastResult = '';
                    break;

                case 'rejectAwakeable':
                    $id = $ctx->get(self::awkKey((string) $command['awakeableKey']));
                    if (!\is_string($id)) {
                        throw new TerminalException('Awakeable is not registered yet');
                    }
                    $ctx->rejectAwakeable($id, (string) ($command['reason'] ?? ''));
                    $lastResult = '';
                    break;

                case 'getEnvVariable':
                    $lastResult = \getenv((string) $command['envName']) ?: '';
                    break;

                default:
                    throw new TerminalException("Unknown command type: {$type}");
            }

            $results = $ctx->get(self::RESULTS);
            $results = \is_array($results) ? $results : [];
            $results[] = $lastResult;
            $ctx->set(self::RESULTS, $results);
        }

        return $lastResult;
    }

    /**
     * @param array{awakeableKey?: string, value?: string} $req
     */
    #[Shared]
    public function resolveAwakeable(SharedObjectContext $ctx, array $req): void
    {
        $id = $ctx->get(self::awkKey((string) ($req['awakeableKey'] ?? '')));
        if (!\is_string($id)) {
            throw new TerminalException('Awakeable is not registered yet');
        }

        $ctx->resolveAwakeable($id, $req['value'] ?? '');
    }

    /**
     * @param array{awakeableKey?: string, reason?: string} $req
     */
    #[Shared]
    public function rejectAwakeable(SharedObjectContext $ctx, array $req): void
    {
        $id = $ctx->get(self::awkKey((string) ($req['awakeableKey'] ?? '')));
        if (!\is_string($id)) {
            throw new TerminalException('Awakeable is not registered yet');
        }

        $ctx->rejectAwakeable($id, (string) ($req['reason'] ?? ''));
    }

    #[Shared]
    public function hasAwakeable(SharedObjectContext $ctx, string $awakeableKey): bool
    {
        return $ctx->get(self::awkKey($awakeableKey)) !== null;
    }

    /**
     * @return list<string>
     */
    #[Shared]
    public function getResults(SharedObjectContext $ctx): array
    {
        $results = $ctx->get(self::RESULTS);

        return \is_array($results) ? $results : [];
    }

    /**
     * Runs a single awaitable command to completion (blocking), returning its result.
     *
     * @param array<string, mixed> $command
     */
    private function runAwaitableCommand(ObjectContext $ctx, array $command): string
    {
        $type = $command['type'] ?? '';

        switch ($type) {
            case 'createAwakeable':
                $awakeable = $ctx->awakeable();
                $ctx->set(self::awkKey((string) $command['awakeableKey']), $awakeable->id());

                return self::asString($awakeable->await());

            case 'sleep':
                $ctx->sleep(((float) $command['timeoutMillis']) / 1000);

                return 'sleep';

            case 'runThrowTerminalException':
                $reason = (string) ($command['reason'] ?? '');

                return self::asString($ctx->run('cmd', static function () use ($reason): string {
                    throw new TerminalException($reason);
                }));

            default:
                throw new TerminalException("Unknown awaitable command type: {$type}");
        }
    }

    /**
     * Builds durable futures for a list of awaitable commands, for concurrent racing.
     *
     * @param list<array<string, mixed>> $commands
     *
     * @return list<DurableFuture>
     */
    private function awaitableFutures(ObjectContext $ctx, array $commands): array
    {
        $futures = [];
        foreach ($commands as $command) {
            $futures[] = $this->awaitableCommandFuture($ctx, $command);
        }

        return $futures;
    }

    /**
     * @param array<string, mixed> $command
     */
    private function awaitableCommandFuture(ObjectContext $ctx, array $command): DurableFuture
    {
        $type = $command['type'] ?? '';

        switch ($type) {
            case 'createAwakeable':
                $awakeable = $ctx->awakeable();
                $ctx->set(self::awkKey((string) $command['awakeableKey']), $awakeable->id());

                return self::awakeableFuture($awakeable);

            case 'sleep':
                return $ctx->timer(((float) $command['timeoutMillis']) / 1000);

            default:
                // See the class-level limitation note: a blocking run cannot be raced.
                throw new TerminalException(
                    "AwaitableCommand '{$type}' is not supported inside awaitAny/awaitAnySuccessful in the PHP SDK",
                );
        }
    }

    /**
     * Extracts the underlying durable future from an awakeable so it can be raced via
     * {@see ObjectContext::select()}. The future is a private property of
     * {@see Awakeable} (only id()/await() are public), so reflection is used to read
     * it without modifying the SDK.
     */
    private static function awakeableFuture(Awakeable $awakeable): DurableFuture
    {
        $future = (new ReflectionProperty(Awakeable::class, 'future'))->getValue($awakeable);
        \assert($future instanceof DurableFuture);

        return $future;
    }

    private static function awkKey(string $awakeableKey): string
    {
        return 'awk-' . $awakeableKey;
    }

    private static function asString(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }

        return \is_scalar($value) ? (string) $value : '';
    }
}
