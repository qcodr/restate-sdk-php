# Restate PHP SDK

[![CI](https://github.com/qcodr/restate-sdk-php/actions/workflows/ci.yml/badge.svg)](https://github.com/qcodr/restate-sdk-php/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/qcodr/restate-sdk-php/branch/main/graph/badge.svg)](https://codecov.io/gh/qcodr/restate-sdk-php)
[![PHPStan level max](https://img.shields.io/badge/PHPStan-level%20max-brightgreen.svg)](phpstan.neon)
[![Psalm type coverage](https://shepherd.dev/github/qcodr/restate-sdk-php/coverage.svg)](https://shepherd.dev/github/qcodr/restate-sdk-php)
[![Mutation testing badge](https://img.shields.io/endpoint?style=flat&url=https%3A%2F%2Fbadge-api.stryker-mutator.io%2Fgithub.com%2Fqcodr%2Frestate-sdk-php%2Fmain)](https://dashboard.stryker-mutator.io/reports/github.com/qcodr/restate-sdk-php/main)
[![Latest Stable Version](https://img.shields.io/packagist/v/qcodr/restate-sdk-php.svg)](https://packagist.org/packages/qcodr/restate-sdk-php)
[![Total Downloads](https://img.shields.io/packagist/dt/qcodr/restate-sdk-php.svg)](https://packagist.org/packages/qcodr/restate-sdk-php)
[![PHP Version](https://img.shields.io/packagist/php-v/qcodr/restate-sdk-php.svg)](https://packagist.org/packages/qcodr/restate-sdk-php)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)
<!-- Code quality grade — connect the repo on https://app.codacy.com, then replace
     CODACY_PROJECT_ID with the project id from the badge snippet and uncomment:
[![Codacy Badge](https://app.codacy.com/project/badge/Grade/CODACY_PROJECT_ID)](https://app.codacy.com/gh/qcodr/restate-sdk-php/dashboard)
-->

A pure-PHP SDK for [Restate](https://restate.dev) — durable execution for
**Services**, **Virtual Objects**, and **Workflows**. It mirrors the
[Rust SDK](https://github.com/restatedev/sdk-rust) surface with idiomatic PHP:
attributes for service definitions, a typed context API, and a Swoole-based server.

The Restate **service protocol (v5–v7)** is implemented from scratch in pure PHP —
framing, protobuf messages, the journal/replay state machine, and suspension — so
the SDK has no native-extension dependency for its core (only the server transport
needs `ext-swoole`).

## Features

- **Services** — stateless handlers, unlimited concurrency.
- **Virtual Objects** — per-key state with single-writer (`#[Handler]`) and
  concurrent read-only (`#[Shared]`) handlers.
- **Workflows** — exactly-once `run` handler plus interaction handlers, with
  **durable promises**.
- **Durable building blocks** — `run` (side effects), `sleep` (durable timers),
  service/object/workflow **calls** and one-way **sends** (with delay), **awakeables**,
  deterministic randomness, and **`select` / `awaitAll`** combinators.
- **Deterministic replay** — every interaction is journaled; handlers replay
  faithfully after failures.

## Requirements

- PHP **8.2+** (`ext-json`, `ext-mbstring`)
- `ext-swoole` to run the server (provided by the Docker image)
- Docker + Docker Compose for end-to-end testing

## Installation

```bash
composer require qcodr/restate-sdk-php
```

## Quick start

Define services with attributes; the first parameter is always the context.

```php
use Restate\Sdk\Context\{Context, ObjectContext, SharedObjectContext};
use Restate\Sdk\Service\Attribute\{Service, VirtualObject, Handler, Shared};

#[Service]
final class Greeter
{
    #[Handler]
    public function greet(Context $ctx, string $name): string
    {
        return "Greetings {$name}";
    }
}

#[VirtualObject]
final class Counter
{
    #[Handler] // exclusive: may write state
    public function add(ObjectContext $ctx, int $delta): int
    {
        $next = ($ctx->get('count') ?? 0) + $delta;
        $ctx->set('count', $next);
        return $next;
    }

    #[Shared] // read-only, concurrent
    public function get(SharedObjectContext $ctx): int
    {
        return $ctx->get('count') ?? 0;
    }
}
```

Serve them:

```php
use Restate\Sdk\Endpoint\Endpoint;
use Restate\Sdk\Server\SwooleServer;

$endpoint = Endpoint::builder()
    ->bind(new Greeter())
    ->bind(new Counter())
    ->build();

(new SwooleServer($endpoint))->listen('0.0.0.0', 9080);
```

Register the deployment with a running Restate server, then invoke through the ingress:

```bash
restate deployments register http://localhost:9080 --use-http1.1
curl localhost:8080/Greeter/greet      -d '"world"'   # "Greetings world"
curl localhost:8080/Counter/acme/add   -d '5'         # 5
```

## Workflows & durable promises

```php
use Restate\Sdk\Context\{WorkflowContext, SharedWorkflowContext};
use Restate\Sdk\Service\Attribute\{Workflow, Handler, Shared};

#[Workflow]
final class SignupWorkflow
{
    #[Handler]
    public function run(WorkflowContext $ctx, string $email): string
    {
        $ctx->set('email', $email);
        $token = $ctx->promise('email-verified'); // suspends until resolved
        return "verified:{$email}:{$token}";
    }

    #[Shared]
    public function verify(SharedWorkflowContext $ctx, string $token): void
    {
        $ctx->resolvePromise('email-verified', $token);
    }
}
```

## Context API

> **Service classes must be stateless.** A bound service instance is shared across
> concurrent invocations within a Swoole worker — keep per-invocation data in local
> variables or Restate state (`$ctx->set(...)`), never in mutable instance properties.

| Capability      | Methods |
|-----------------|---------|
| Side effects    | `run(name, fn, ?RunOptions)` — with optional per-run `RetryPolicy` |
| Timers          | `sleep(seconds)`, `timer(seconds)` → `DurableFuture` |
| Calls (await)   | `serviceCall`, `objectCall`, `workflowCall` (+ `idempotencyKey`, `headers`) |
| Calls (async)   | `serviceCallAsync`, `objectCallAsync`, `workflowCallAsync` → `DurableFuture` |
| Calls (handle)  | `serviceCallHandle`, … → `CallHandle` (`result()`, `invocationId()`) |
| One-way sends   | `serviceSend`, `objectSend`, `workflowSend` (optional delay) |
| Cancellation    | `cancel(invocationId)` — peer cancel; observed as `CancelledException` (409) |
| Combinators     | `select`, `awaitAll`, `awaitAny` (any), `awaitAllSucceeded` (all-or-fail) |
| Tracing         | `traceContext()` — W3C trace context (bridge to OpenTelemetry) |
| Awakeables      | `awakeable()`, `resolveAwakeable`, `rejectAwakeable` |
| State (objects) | `get`, `set`, `clear`, `clearAll`, `stateKeys` |
| Promises (wf)   | `promise`, `peekPromise`, `resolvePromise`, `rejectPromise` |
| Request meta    | `key()`, `invocationId()`, `requestHeaders()`, `requestIdempotencyKey()` |
| Randomness      | `random()->uuidV4()`, `randomInt`, `randomFloat` |
| Logging         | `logger()` — a replay-aware PSR-3 logger |

**Errors:** throw `TerminalException` for a non-retryable failure returned to the
caller; `RetryableException` (optionally `pause: true` or a `retryDelayMillis`) for a
tuned transient failure; any other throwable is a plain transient error (retried).

### Logging & tracing

`ctx->logger()` returns a **PSR-3** logger that suppresses records emitted during
replay, so each line is logged exactly once even though handlers re-run from the top
on every slice. Provide the underlying logger (e.g. Monolog) when constructing the
server: `new SwooleServer($endpoint, logger: $myLogger)` (defaults to a null logger).

For distributed **tracing**, mind the propagation boundary:

- **Across the service graph** (the services your handler calls or sends to) — the
  **Restate runtime** propagates the trace. It stamps `traceparent` on the request it
  sends the SDK and links child invocations itself. Do **not** manually forward
  `traceparent` on `ctx->serviceCall(...)` headers; doing so forks the trace.
- **Inside one handler** (spans around your own DB/HTTP/compute work) — that's yours.
  `ctx->traceContext()` exposes the inbound W3C context (`traceId`, `spanId()`,
  `isSampled()`, `toTraceparent()`) so your spans nest under the incoming trace.

The SDK stays dependency-free and emits no spans itself. Install `open-telemetry/sdk`
and use the `withIncomingTraceParent()` bridge in `examples/tracing.php` to start spans
under the incoming trace.

## Production configuration

**Discovery options.** Configure per-service / per-handler behavior the runtime reads
from the manifest (negotiated up to schema v4):

```php
use Restate\Sdk\Service\{ServiceOptions, HandlerOptions, RetryPolicyOnMaxAttempts};

$endpoint = Endpoint::builder()
    ->bindWithOptions(new Counter(), (new ServiceOptions(
        inactivityTimeoutMillis: 60_000,
        idempotencyRetentionMillis: 86_400_000,
        ingressPrivate: false,
        metadata: ['team' => 'orders'],
    ))->withHandler('add', new HandlerOptions(
        retryPolicyMaxAttempts: 5,
        retryPolicyOnMaxAttempts: RetryPolicyOnMaxAttempts::Pause,
    )))
    ->build();
```

**Request identity verification** (opt-in; requires `ext-sodium`). Reject requests
not signed by your Restate instance's key:

```php
$endpoint = Endpoint::builder()
    ->bind(new Greeter())
    ->identityKey('publickeyv1_...')   // unsigned/invalid requests → 401
    ->build();
```

**Transports.** Besides `SwooleServer`, the framework-agnostic core is hostable via a
**PSR-15** adapter (`Restate\Sdk\Server\Psr15Handler`) in any Slim/Mezzio stack, on
**AWS Lambda** (`Restate\Sdk\Server\LambdaHandler` — Function URL / API Gateway proxy),
and directly via `RequestProcessor` (bytes in → bytes out).

**Typed clients.** `bin/restate-codegen <ServiceClass> [outDir] [namespace]` generates
an IDE-autocompletable client so callers write
`GreeterClient::fromContext($ctx)->greet('world')` instead of stringly-typed
`$ctx->serviceCall('Greeter','greet','world')`. The discovery manifest also carries a
**JSON Schema** for each handler's input/output, derived from the PHP types.

**Serde.** JSON is the default; `BytesSerde` provides raw octet-stream passthrough.
Inject a custom `Serde` into the server/processor for other formats.

## Examples

The `examples/` directory ports the Rust SDK's examples to PHP. Each file is a
self-contained, runnable endpoint.

| Example | Shows |
|---------|-------|
| `greeter.php` | the simplest stateless service |
| `counter.php` | Virtual Object state (get / add / increment / reset) |
| `run.php` | durable side effects (`ctx->run`) around an HTTP call |
| `failures.php` | terminal (no-retry) vs transient (retried) errors |
| `fan_out.php` | concurrent durable timers via `timer()` + `select()` |
| `schema.php` | structured JSON input/output + scalars |
| `cron.php` | a periodic task that re-schedules itself with delayed sends |
| `services.php` | the canonical Service + Virtual Object + Workflow trio |
| `tracing.php` | replay-aware PSR-3 logging (run standalone: `php examples/tracing.php`) |

Run a single example with the bundled server:

```bash
php bin/restate-serve examples/counter.php          # serves on :9080
restate deployments register http://localhost:9080 --use-http1.1
curl localhost:8080/Counter/my-key/increment
```

Or bring all of them up live (Docker), against a real runtime:

```bash
make examples            # builds + registers the example endpoint
curl localhost:8080/FanOut/fanOut     # -> "Completed in order: fast, medium, slow"
```

## Testing

Unit tests run anywhere (no extensions, no Docker):

```bash
composer install
composer test          # vendor/bin/phpunit --testsuite unit
```

End-to-end verification is the **official cross-SDK conformance suite**
([`restatedev/sdk-test-suite`](https://github.com/restatedev/sdk-test-suite)) — the
same battery every Restate SDK runs. It boots a real Restate runtime + a PHP image of
the standard test-services and drives them:

```bash
make conformance               # downloads the suite, builds the image, runs `default`
make conformance TEST_SUITE=all
```

The `default` config passes **30/30** (8 documented exclusions). See
[`conformance/README.md`](conformance/README.md).

To try the example services live by hand:

```bash
make examples                  # curl localhost:8080/FanOut/fanOut
make down
```

## Code quality

The project ships a strict static-analysis, coding-standard, and security gate —
all of it runs **fully offline** (no cloud/SaaS):

- **PHPStan** at the **max** level over `src` and `tests` (ext-swoole is
  stubbed in `stubs/`).
- **PHP-CS-Fixer** with PSR-12 + risky strictness rules (`declare(strict_types=1)`,
  strict comparisons, strict params, namespaced native calls).
- **Psalm taint analysis** as the offline **SAST** engine — traces untrusted input
  (request bytes, CLI args) to dangerous sinks (dynamic include, eval, exec, SQL).
  Type quality is owned by PHPStan, so Psalm's errorLevel is kept permissive and it
  focuses purely on security taint flows.

```bash
make lint      # php-cs-fixer (check) + phpstan          (== composer lint)
make stan      # phpstan only                            (== composer stan)
make cs        # coding-standard check (no changes)      (== composer cs)
make cs-fix    # apply the coding standard               (== composer cs:fix)
make sast      # psalm taint analysis (SAST)             (== composer sast)
make check     # lint + sast + unit tests (pre-commit)   (== composer check)
```

> The Compose file pins `restatedev/restate:1.5.2`. The `:latest` image targets
> newer CPUs (AVX2) and may crash on older hardware.

## Architecture

```
src/
  Protocol/   wire protocol: 64-bit framing, hand-rolled protobuf codec, messages
  Vm/         StateMachine — journal replay, completion table, suspension, eager state
  Discovery/  endpoint manifest builder + content-type negotiation
  Service/    attributes + reflection-based service/handler definitions
  Context/    typed context API (Service / Object / Workflow) over the VM
  Serde/      JSON (de)serialization
  Endpoint/   framework-agnostic RequestProcessor + transport DTOs
  Server/     SwooleServer transport adapter
```

The framework-agnostic `RequestProcessor` (bytes in → bytes out) is the testable
core; `SwooleServer` is one swappable transport. Transport mode is
`REQUEST_RESPONSE`: the runtime sends `StartMessage` + the replayed journal, the SDK
processes one slice and suspends when it awaits a result it does not yet have; the
runtime re-invokes with a longer journal and the handler replays from the top.

## License

[Apache-2.0](LICENSE)
