# Benchmarks

Performance is measured at two levels, each with a different purpose:

| Layer | What it measures | Reproducible where | Use |
|-------|------------------|--------------------|-----|
| **Micro** (`benchmarks/micro.php`) | CPU + memory of the SDK hot path (protocol codec, journal/replay state machine, typed context + serde) — **no I/O** | Any PHP host, CI | Regression gate; isolates SDK cost from network/runtime |
| **End-to-end** (`benchmarks/e2e/run.sh`) | Real request path: load generator → Restate ingress → runtime → PHP Swoole endpoint → response. Latency, throughput, worker memory | Any host with Docker | Realistic latency/throughput + leak detection under sustained load |

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

# End-to-end (brings up Restate 1.5.2 + the Swoole endpoint via docker compose,
# drives it with a containerized `oha`, samples worker RSS with `docker stats`)
make bench-e2e
# or, tuning the load:
DURATION=30s MEM_DURATION=360s CONNECTIONS=100 benchmarks/e2e/run.sh
KEEP_UP=1 benchmarks/e2e/run.sh        # leave the stack up for inspection
```

The only external tool the end-to-end harness needs is Docker; the load generator
([`oha`](https://github.com/hatoo/oha)) runs as a container (`ghcr.io/hatoo/oha`),
so nothing is installed on the host. Raw results are written to
`build/benchmarks/` (`e2e-oha.json`, `e2e-mem.csv`).

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
