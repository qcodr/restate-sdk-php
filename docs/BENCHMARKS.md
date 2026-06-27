# Benchmarks

Performance is measured at two levels, each with a different purpose:

| Layer | What it measures | Reproducible where | Use |
|-------|------------------|--------------------|-----|
| **Micro** (`benchmarks/micro.php`) | CPU + memory of the SDK hot path (protocol codec, journal/replay state machine, typed context + serde) — **no I/O** | Any PHP host, CI | Regression gate; isolates SDK cost from network/runtime |
| **End-to-end** (`benchmarks/e2e/run.sh`) | Real request path: load generator → Restate ingress → runtime → PHP endpoint → response, over Swoole (r/r) *or* amphp (bidi). Latency, throughput, endpoint memory | Any host with Docker | Realistic latency/throughput + leak detection under sustained load + transport comparison |

The micro layer is the authoritative number for *SDK overhead* and for catching
regressions, because it is deterministic and dependency-free. The end-to-end layer
gives realistic latency/throughput, but those numbers are dominated by the Restate
runtime round-trip and journal persistence, **not** by the SDK.

> Benchmarks are not run in CI as a pass/fail gate (load numbers are
> hardware-dependent). The micro-benchmark can be run anywhere for a quick
> regression check; the reference numbers below are stamped with the hardware they
> were taken on.

---

## How to run

```bash
# SDK hot-path micro-benchmark (pure PHP, no Docker)
make bench
# or, tuning the loop and emitting JSON:
BENCH_ITER=100000 BENCH_WARMUP=10000 BENCH_JSON=1 php benchmarks/micro.php

# End-to-end (brings up Restate 1.5.2 + a PHP endpoint via docker compose, drives it
# with a containerized `oha`, samples endpoint RSS with `docker stats`)
make bench-e2e                          # Swoole request/response (default)
make bench-e2e-amp                      # amphp bidi HTTP/2
make bench-e2e-compare                  # both, side by side, one runtime
# or, tuning the load:
TRANSPORT=amp DURATION=30s MEM_DURATION=360s CONNECTIONS=100 benchmarks/e2e/run.sh
KEEP_UP=1 benchmarks/e2e/run.sh        # leave the stack up for inspection
```

The only external tool the end-to-end harness needs is Docker; the load generator
([`oha`](https://github.com/hatoo/oha)) runs as a container (`ghcr.io/hatoo/oha`),
so nothing is installed on the host. Raw results are written to
`build/benchmarks/` (`e2e-oha-<transport>.json`, `e2e-mem-<transport>.csv`).

---

## Reference environment

The numbers below were captured on a deliberately **modest, older** host, so treat
them as a conservative floor — a modern CPU with opcache JIT is typically several
times faster.

| | |
|---|---|
| CPU | Intel Xeon E5-2650 v2 @ 2.60GHz (Ivy Bridge, 2013) — **no AVX2** |
| Cores / RAM | 32 threads / 125 GiB |
| OS | Linux 6.12 |
| PHP | 8.4.21 (micro: CLI, **no JIT**; e2e: Swoole image) |
| Restate | **1.5.2** (pinned) |

> **Why Restate 1.5.2?** Restate ≥ 1.6 requires AVX2, which this host lacks (it
> SIGILLs). The end-to-end harness therefore pins 1.5.2. On AVX2-capable hardware,
> point it at a current runtime: `RESTATE_CONTAINER_IMAGE`/compose override to
> `restatedev/restate:latest`.

---

## Results

### Micro-benchmark (SDK hot path)

`make bench` — 50,000 iterations, 5,000 warmup, PHP 8.4 CLI (no JIT):

| Scenario | ns/op | ops/sec |
|----------|------:|--------:|
| `protocol_decode` — decode an outgoing frame stream | 11,186 | 89,400 |
| `invocation_service` — parse journal, read+write state, terminate | 69,219 | 14,447 |
| `invocation_call_replay` — replay a service call + consume completion | 46,843 | 21,348 |
| `context_state_serde` — typed `get`/`set` through JSON serde | 61,209 | 16,337 |

**Memory** — 200,000 invocations in a single process (simulating the long-lived
Swoole worker), forced GC between samples:

- Peak heap: **8 MB**
- Steady growth: **0 bytes / invocation** → no leak in SDK objects.

Interpretation: a full journaled invocation costs **~50–70 µs of pure SDK CPU** on
this old hardware (no JIT). The SDK is not the bottleneck — see the end-to-end
latency below.

### End-to-end (oha → Restate → Swoole endpoint)

`make bench-e2e` — 50 concurrent connections against the stateless `Greeter/greet`
handler:

| Metric | Value |
|--------|------:|
| Throughput | **1,727 req/s** |
| Success rate | 100.00% |
| Latency p50 | 28.7 ms |
| Latency p90 | 35.3 ms |
| Latency p99 | 44.4 ms |
| Latency max | 66.8 ms |

**Memory / leak** — 6-minute sustained soak, RSS sampled every 5 s via `docker stats`:

- RSS: starts ~51 MB, warms to a **~57 MB plateau** (opcache fill + Swoole
  connection buffers).
- Steady-state slope (least-squares over the second half): **−0.12 MB/min** → no
  leak under sustained load.

Interpretation: end-to-end latency (~29 ms p50) is dominated by the Restate runtime
round-trip and journal persistence, not the SDK (~0.05 ms of which is SDK CPU per
the micro-benchmark). Throughput here is bounded by this old CPU and a single Swoole
worker config; scale with `worker_num` and faster hardware.

### Transport comparison: Swoole (r/r) vs amphp (bidi)

`make bench-e2e-compare` runs the identical `BenchGreeter/greet` over both transports
against one runtime, head to head. On a **16-core AVX2 host, Restate 1.5.2, 50
connections, 20 s** (numbers are relative — the absolute rate is host-dependent):

| Transport | req/s | p50 | p90 | p99 | peak RSS |
|-----------|------:|----:|----:|----:|---------:|
| Swoole r/r — 16 workers (default) | 970 | 47 ms | 74 ms | 136 ms | 39 MB |
| Swoole r/r — 1 worker (`WORKER_NUM=1`) | 1,228 | 38 ms | 57 ms | 94 ms | — |
| amphp bidi — before TCP_NODELAY | 495 | 89 ms | 170 ms | 274 ms | 44 MB |
| **amphp bidi — 1 process** | **660** | 63 ms | 135 ms | 250 ms | 44 MB |

Two findings:

- **The workload is runtime-bound, not endpoint-bound.** Swoole with *one* worker
  (1,228 req/s) beats Swoole with sixteen (970): more workers do not help, because the
  limiter is the Restate runtime's single-partition journaling, not PHP (same
  conclusion as the worker_num sweep below).
- **At one process, a zero-suspension handler costs ~1.8× per request on bidi.** At equal
  concurrency and one process (`-c 50`, 1 worker) Swoole does ~1,200 req/s, bidi ~660: pure
  transport overhead, since the bidi streaming driver (fiber park/resume, a held-open HTTP/2
  stream, the frame queue) does strictly more work per call than Swoole's request/response.
  A trivial greeter is bidi's *worst* case — it never suspends, so none of bidi's advantage
  applies. (This per-request gap is closed by running multiple workers — see below.)

> **Optimizing the bidi transport.** Three layered wins, each measured:
>
> 1. **`TCP_NODELAY`** — amphp's `BindContext` leaves it off, so a slice that writes a
>    couple of small frames (Output then End) and then waits to read hit Nagle ↔
>    delayed-ACK for a ~40 ms per-invocation stall. Enabling it on the server socket (plus
>    coalescing each slice's frames into one write in `AmpStreamTransport`) lifted the
>    greeter from **495 → ~660 req/s (+35%)**, p50 89 → 63 ms.
> 2. **Inline fast-path** — the original `stream()` spawned an `async()` task + an outbound
>    `Queue` + `ReadableIterableStream` for *every* invocation: ~17.7 µs to create the
>    extra Fiber, ~3.4 µs for the queue, and 2–3 event-loop hops per call (a micro-benchmark
>    put `ReadableBuffer::read` at 465 ns vs 3,416 ns for the queue path — 7.3×). A handler
>    that does not park now runs entirely in the request fiber and returns its whole output
>    as one `ReadableBuffer` — no async, no queue, zero extra hops; only a *parked* handler
>    falls back to the streaming queue (`SwitchableOutputSink` buffers inline, then forwards
>    to the transport on the first park). Worth **+15–22%** more single-worker throughput
>    and a notably tighter tail under load.
> 3. **Multi-worker** — see below; it removes the single-event-loop ceiling entirely.

#### Multi-worker bidi (lifting the single-event-loop ceiling)

One amphp process is one event loop — a single-core ceiling. At `-c 50` it serves ~520
req/s; pushing concurrency only grows latency until it **collapses** (`-c 400` → 123 req/s,
p50 734 ms) as a single HTTP/2 connection / loop saturates. `AmpStreamingServer::listen()`
takes a **`$workers`** count: it pre-forks N processes that each bind the port with
`SO_REUSEPORT`, so the kernel load-balances connections across N event loops — the amphp
equivalent of Swoole's worker pool. Same host, Restate 1.5.2, `BenchGreeter/greet`:

| workers | `-c 50` | `-c 200` | `-c 400` |
|--------:|--------:|---------:|---------:|
| 1 | 522 | 693 | 123 💥 |
| 8 | 743 | 1,421 | 1,364 |
| 16 | 805 | **1,470** | **1,709** |

With 8–16 workers the bidi transport **matches and overtakes** single-worker Swoole
(~1,228) — 1,470 req/s at `-c 200`, 1,709 at `-c 400`, 100 % success, and the collapse is
gone. Beyond that the limiter is the Restate runtime again, not the SDK. So the structural
per-request overhead is a *single-process* property; throughput parity is a `WORKER_NUM`
away (`bench-endpoint-amp.php` reads it; production sets `listen(..., workers: N)`).

When bidi pays off is the opposite workload — handlers that **suspend** (sleep, durable
calls, awakeables, `select`). Over request/response every suspension is a full
re-invocation HTTP round-trip (the runtime replays a longer journal from the top); over
bidi the runtime streams the completion onto the open channel and the SDK resumes the
parked fiber in place, no re-invoke. Bidi is also the only transport that can deliver
**cancellation / signals** (V7) to a parked invocation at all. Choose bidi for
correctness and suspension-heavy durable workflows; either transport is fine (Swoole is
faster) for fire-and-forget stateless calls.

---

## Scaling and bottleneck analysis

Sweeping concurrent connections against `Greeter/greet` (default workers):

| Connections | req/s | p50 | p99 | Success |
|------------:|------:|----:|----:|--------:|
| 25 | 1,108 | 22ms | 34ms | 100% |
| 50 | 1,750 | 28ms | 46ms | 100% |
| 100 | 2,583 | 38ms | 56ms | 100% |
| 200 | 3,588 | 54ms | 88ms | 100% |
| 400 | 4,067 | 95ms | 161ms | 100% |
| **800** | **5,693** | 125ms | 321ms | 100% |
| 3,200 | 3,012 | 307ms | 640ms | 93.5% ⚠ |

Peak sustainable throughput is **~5,700 req/s at 800 connections, 100% success**. The
knee is around 800; beyond ~1,600 the system overloads — at 3,200 connections
throughput *collapses* and the success rate drops to 93.5%.

**The limiter is the Restate runtime, not the SDK and not the hardware.** The host
has 32 cores; a plain stateless HTTP server reaches hundreds of thousands of req/s on
it, so the box itself is not the cap. The cost is inherent to durable execution:
every request is journaled (RocksDB) and incurs an internal HTTP/2 hop from the
runtime to the endpoint. The controlled experiment below quantifies it.

### Controlled CPU comparison (worker_num sweep)

A fair comparison requires fixing each side's CPU rather than letting the two
containers fight over 32 cores. Both are given explicit budgets
(`docker run --cpus` / `docker update --cpus`): **Restate is pinned at 16 cores** and
the endpoint at `worker_num` cores, then `worker_num` is swept at `-c 400`. Both
containers' CPU is sampled under load:

| `worker_num` (= endpoint cores) | req/s | p50 | p99 | Endpoint CPU | Restate CPU |
|------------:|------:|----:|----:|------------:|------------:|
| 2 | 2,955 | 87ms | 488ms | 204% (2 cores — **capped**) | ~1,213% (~12 cores) |
| 4 | 4,034 | 96ms | 169ms | 273% (~2.7 cores) | ~1,366% (~14 cores) |
| 8 | 4,038 | 96ms | 168ms | 296% (~3 cores) | ~1,248% (~12 cores) |
| 16 | 3,958 | 93ms | 216ms | 280% (~3 cores) | ~1,298% (~13 cores) |

The picture is unambiguous: the **Restate runtime burns ~12–14 cores** to drive
~4,000 req/s, while the **PHP/Swoole endpoint (this SDK) needs only ~3 cores** for the
same load — roughly a **4:1 CPU ratio in the runtime's favor**. At `worker_num = 2`
the endpoint is co-limiting (pinned at its 2-core budget → 2,955 req/s); from
`worker_num = 4` it draws under 3 of its allotted cores, so it is no longer the
bottleneck and throughput plateaus on Restate (~4,000 req/s).

So the SDK is CPU-efficient; durable-execution throughput on this box is gated by the
runtime. `SwooleServer::listen()` defaults to `max(2, swoole_cpu_num())`, which is
safe but oversized for a CPU-light handler — **~4 workers** already feed the runtime
to saturation here. Absolute req/s and p99 carry ±15–20% across single 15 s runs; the
CPU ratio and "the endpoint stops being the bottleneck at ~4 workers" are the robust
conclusions.

Reproduce with the tunable endpoint:

```bash
WORKER_NUM=4 PORT=9080 php benchmarks/e2e/bench-endpoint.php   # bind one service, N workers
```

### opcache and JIT on the endpoint

A natural question is whether opcache + JIT lighten the PHP endpoint. On the shipped
Swoole server, **neither helps**:

- **JIT is unavailable under Swoole.** `ext-swoole` installs user opcode handlers, so
  PHP disables JIT at startup: *"JIT is incompatible with third party extensions that
  setup user opcode handlers. JIT disabled."* The Swoole server always runs without
  JIT.
- **opcache makes no measurable difference.** It is present but `opcache.enable_cli`
  defaults off, and enabling it does not move the needle: a long-lived Swoole worker
  compiles each file once at boot, so opcache's per-request re-parse savings (the win
  in an FPM/per-request SAPI) simply do not apply. opcache-on vs -off at `-c 400`
  stayed within run-to-run noise (±15–20%), with no consistent direction.

This is not a limitation in practice: the endpoint already uses only ~3 cores and the
runtime is the bottleneck, so there is nothing to gain. Teams that specifically want
JIT can serve the SDK through **php-fpm behind a web server using the PSR-15 handler**
(`Psr15Handler`) instead of Swoole — FPM permits JIT and benefits from opcache — at
the cost of per-request bootstrap that the persistent Swoole worker avoids.

## Methodology notes

- **Timing**: `hrtime(true)` (monotonic ns), reported as median ns/op over the timed
  loop after an untimed warmup.
- **Latency percentiles**: from `oha`'s histogram (p50/p90/p99), not averages.
- **Leak detection**: RSS is sampled over time; the verdict uses a **least-squares
  slope over the steady-state second half** of the run, which is robust to
  `docker stats`' ~0.1 MiB quantization (a naive first-vs-last difference reads
  warmup as a false "leak"). The micro-benchmark cross-checks this deterministically
  at 0 bytes/invocation.
- **Warmup matters**: the first ~30 s of an E2E run is opcache/JIT and connection
  ramp-up; the leak slope deliberately excludes it.

## Caveats

- Numbers are **hardware- and configuration-specific**. Re-run on your target
  hardware; do not treat these as guarantees.
- The micro-benchmark runs without JIT (CLI); the production Swoole server runs with
  opcache, so real per-invocation SDK cost is lower than the micro figures.
- The end-to-end run uses a single Swoole worker and Restate 1.5.2 on a 2013 CPU —
  a floor, not a ceiling.
- These are throughput/latency/memory benchmarks, not a correctness suite. Behavior
  is covered by the unit tests (98% line, 89% mutation score) and the cross-SDK
  conformance suite.
