<?php

declare(strict_types=1);

/**
 * SDK hot-path micro-benchmark — pure PHP, no network, no runtime, no extensions.
 *
 * Measures the CPU and memory cost of the parts of the SDK that run on every
 * invocation: protocol codec (encode/decode), the journal/replay state machine, and
 * the typed context with serde. Because it does no I/O it is fully deterministic and
 * reproducible on any PHP host (CI included), which makes it a regression gate rather
 * than an absolute-throughput claim. End-to-end numbers (through Restate + Swoole)
 * live in the e2e harness; see docs/BENCHMARKS.md.
 *
 * Usage:
 *   php benchmarks/micro.php                  # human-readable table
 *   BENCH_JSON=1 php benchmarks/micro.php     # machine-readable JSON on stdout
 *   BENCH_ITER=100000 BENCH_WARMUP=10000 php benchmarks/micro.php
 */

namespace Qcodr\Restate\Sdk\Benchmarks;

use Qcodr\Restate\Sdk\Context\ContextRand;
use Qcodr\Restate\Sdk\Context\RestateContext;
use Qcodr\Restate\Sdk\Context\SystemClock;
use Qcodr\Restate\Sdk\Protocol\MessageCodec;
use Qcodr\Restate\Sdk\Protocol\MessageType;
use Qcodr\Restate\Sdk\Protocol\ServiceProtocolVersion;
use Qcodr\Restate\Sdk\Serde\JsonSerde;
use Qcodr\Restate\Sdk\Tests\Support\JournalBuilder;
use Qcodr\Restate\Sdk\Vm\StateMachine;

require __DIR__ . '/../vendor/autoload.php';

$iterations = (int) (\getenv('BENCH_ITER') ?: 50_000);
$warmup = (int) (\getenv('BENCH_WARMUP') ?: 5_000);
$asJson = \getenv('BENCH_JSON') !== false;

/**
 * Times a single operation over $iterations after $warmup untimed runs.
 *
 * @param callable():void $op
 *
 * @return array{name: string, ops: int, ns_per_op: float, ops_per_sec: float}
 */
function bench(string $name, int $iterations, int $warmup, callable $op): array
{
    for ($i = 0; $i < $warmup; $i++) {
        $op();
    }

    \gc_collect_cycles();
    $start = \hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $op();
    }
    $elapsedNs = \hrtime(true) - $start;

    $nsPerOp = $elapsedNs / $iterations;

    return [
        'name' => $name,
        'ops' => $iterations,
        'ns_per_op' => \round($nsPerOp, 1),
        'ops_per_sec' => \round(1_000_000_000 / $nsPerOp),
    ];
}

// --- Fixtures: realistic frames the runtime would send. ---

$serviceJournal = (new JournalBuilder(stateMap: ['count' => '5']))->input('"world"')->build();

$callJournal = (new JournalBuilder())
    ->input('"x"')
    ->command(MessageType::CallCommand)
    ->callCompletion(2, '"hello"')
    ->build();

$encodedOutput = (static function () use ($serviceJournal): string {
    $vm = new StateMachine(ServiceProtocolVersion::V7);
    $vm->notifyInput($serviceJournal);
    $vm->notifyInputClosed();
    $vm->sysInput();
    $vm->sysWriteOutputSuccess('"Greetings world"');
    $vm->sysEnd();

    return $vm->takeOutput();
})();

// --- Scenarios ---

$results = [];

// 1. Decode an outgoing frame stream back into typed frames.
$results[] = bench('protocol_decode', $iterations, $warmup, static function () use ($encodedOutput): void {
    MessageCodec::decodeAll($encodedOutput);
});

// 2. Full stateless-service invocation: parse journal, read+write state, terminate.
$results[] = bench('invocation_service', $iterations, $warmup, static function () use ($serviceJournal): void {
    $vm = new StateMachine(ServiceProtocolVersion::V7);
    $vm->notifyInput($serviceJournal);
    $vm->notifyInputClosed();
    $vm->sysInput();
    $vm->sysGetState('count');
    $vm->sysSetState('count', '6');
    $vm->sysWriteOutputSuccess('"6"');
    $vm->sysEnd();
    $vm->takeOutput();
});

// 3. Invocation that replays a service call and consumes the completion.
$results[] = bench('invocation_call_replay', $iterations, $warmup, static function () use ($callJournal): void {
    $vm = new StateMachine(ServiceProtocolVersion::V7);
    $vm->notifyInput($callJournal);
    $vm->notifyInputClosed();
    $vm->sysInput();
    [, $resultId] = $vm->sysCall('Svc', 'h', '', '"x"');
    $vm->awaitCompletion($resultId);
    $vm->sysEnd();
    $vm->takeOutput();
});

// 4. Typed context path: state read/write through serde.
$results[] = bench('context_state_serde', $iterations, $warmup, static function () use ($serviceJournal): void {
    $vm = new StateMachine(ServiceProtocolVersion::V7);
    $vm->notifyInput($serviceJournal);
    $vm->notifyInputClosed();
    $input = $vm->sysInput();
    $ctx = new RestateContext(
        $vm,
        $input,
        new JsonSerde(),
        new SystemClock(),
        ContextRand::fromSeed($input->randomSeed),
        writable: true,
        logger: new \Psr\Log\NullLogger(),
    );
    $ctx->get('count');
    $ctx->set('count', ['n' => 6, 'updated' => true]);
});

// --- Memory / leak check: steady-state growth across a long worker lifetime. ---
//
// The Swoole server reuses one process across invocations, so a per-invocation leak
// in SDK objects would accumulate. Run many invocations, force GC, and measure the
// resident growth between two late samples; a near-zero slope means no SDK leak.

$leakIterations = (int) (\getenv('BENCH_LEAK_ITER') ?: 200_000);
$sampleEvery = (int) ($leakIterations / 10);
$samples = [];
for ($i = 1; $i <= $leakIterations; $i++) {
    $vm = new StateMachine(ServiceProtocolVersion::V7);
    $vm->notifyInput($serviceJournal);
    $vm->notifyInputClosed();
    $vm->sysInput();
    $vm->sysGetState('count');
    $vm->sysSetState('count', '6');
    $vm->sysWriteOutputSuccess('"6"');
    $vm->sysEnd();
    $vm->takeOutput();

    if ($i % $sampleEvery === 0) {
        \gc_collect_cycles();
        $samples[$i] = \memory_get_usage(true);
    }
}

// Slope from the second half (post-warmup steady state) to ignore one-time allocations.
$keys = \array_keys($samples);
$mid = $keys[(int) (\count($keys) / 2)];
$last = $keys[\count($keys) - 1];
$bytesPerIteration = ($samples[$last] - $samples[$mid]) / ($last - $mid);

$memory = [
    'leak_iterations' => $leakIterations,
    'heap_peak_bytes' => \memory_get_peak_usage(true),
    'steady_bytes_per_invocation' => \round($bytesPerIteration, 4),
    'samples' => $samples,
];

// --- Report ---

$env = [
    'php_version' => \PHP_VERSION,
    'os' => \php_uname('s') . ' ' . \php_uname('r'),
    'opcache' => \function_exists('opcache_get_status') && \opcache_get_status(false) !== false,
    'iterations' => $iterations,
    'warmup' => $warmup,
];

if ($asJson) {
    echo \json_encode(
        ['environment' => $env, 'throughput' => $results, 'memory' => $memory],
        \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR,
    ), "\n";

    return;
}

\printf("SDK micro-benchmark — PHP %s, %s%s\n", $env['php_version'], $env['os'], $env['opcache'] ? ' (opcache on)' : '');
\printf("iterations=%d warmup=%d\n\n", $iterations, $warmup);
\printf("%-26s %14s %14s\n", 'scenario', 'ns/op', 'ops/sec');
\printf("%s\n", \str_repeat('-', 56));
foreach ($results as $r) {
    \printf("%-26s %14s %14s\n", $r['name'], \number_format($r['ns_per_op'], 1), \number_format($r['ops_per_sec']));
}
\printf("\nMemory (%d invocations, single process):\n", $memory['leak_iterations']);
\printf("  peak heap:                %s\n", \number_format($memory['heap_peak_bytes']) . ' bytes');
\printf("  steady growth/invocation: %s bytes  (%s)\n",
    \number_format($memory['steady_bytes_per_invocation'], 4),
    $memory['steady_bytes_per_invocation'] <= 0.0 ? 'no leak' : 'investigate',
);
