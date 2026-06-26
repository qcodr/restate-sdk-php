# Cross-SDK conformance

This SDK is verified against the **official Restate conformance suite**
([`restatedev/sdk-test-suite`](https://github.com/restatedev/sdk-test-suite)) — the
same battery of tests every Restate SDK (TypeScript, Java, Python, Go, Rust) runs.

`conformance/Services/` ports the standard contract test-services to PHP; the suite
boots a real Restate runtime + this image (via Testcontainers) and drives them.

## Result

`default` configuration — **30 / 30 passing, 0 failures** (Restate 1.5.2):

State · ServiceToServiceCommunication (calls, idempotency keys, delayed sends) ·
WorkflowAPI (durable promises) · Sleep · RunRetry (per-run retry policy) ·
UserErrors (terminal vs retryable propagation across calls and side effects) ·
ProxyRequestSigning (Ed25519 request identity) · Combinators (awakeable-or-timeout) ·
SleepWithFailures · StopRuntime / KillRuntime (durability across restarts) ·
UpgradeWithNewInvocation · KafkaIngress · Ingress (header pass-through).

8 tests are excluded with documented reasons in `exclusions.yaml` (cancellation /
kill of *suspended* invocations, which the Rust SDK passes over the bidirectional
transport this SDK does not implement — see `../docs/adr/0001-request-response-transport.md`;
`awaitAny` combinator edge cases the Rust SDK also excludes; per-handler raw serde;
one fan-out ordering case; in-flight deployment upgrade).

## Run it

Requires Java 21 + Docker. On this host the Restate runtime is pinned to **1.5.2**
(the `:latest` image needs AVX2 and SIGILLs on older CPUs).

```bash
make conformance                       # downloads the suite jar, builds the image, runs `default`
make conformance TEST_SUITE=all        # every configuration
# or a single class while debugging:
java -jar build/restate-sdk-test-suite.jar run \
  --restate-container-image=docker.io/restatedev/restate:1.5.2 \
  --test-suite=default --sequential --test-name=State \
  localhost/restatedev/php-test-services:latest
```

`--sequential` is used because parallel container startup makes the runtime's h2c
discovery handshake flaky on a single host. The PHP server speaks HTTP/2 cleartext
(h2c), which the runtime uses for discovery + invocation.

After a run, `build/conformance-report/<ts>/exclusions.new.yaml` lists everything that
failed/was skipped — copy entries into `exclusions.yaml` to baseline new gaps.
