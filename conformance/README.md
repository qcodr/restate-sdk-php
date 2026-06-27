# Cross-SDK conformance

This SDK is verified against the **official Restate conformance suite**
([`restatedev/e2e`](https://github.com/restatedev/e2e/tree/main/sdk-tests) — the actively
maintained successor to the now-archived `restatedev/sdk-test-suite`) — the same battery
of tests every Restate SDK (TypeScript, Java, Python, Go, Rust) runs.

`conformance/Services/` ports the standard contract test-services to PHP; the suite boots
a real Restate runtime + this image (via Testcontainers) and drives them.

## Result

The primary configuration drives the **bidirectional (amphp) transport on service
protocol V7** — the SDK's default server — against a V7-enabled runtime. The `default`
suite passes **48 / 49**, including `Cancellation` 6/6, `KillInvocation` 1/1, `Signals`
2/2, `Combinators` 9/9, `RunRetry` 3/3, `UserErrors` 10/10, and
`ServiceToServiceCommunication` 5/5.

A few tests are excluded with documented reasons in `exclusions.yaml` (`awaitAny`
combinator edge cases the Rust SDK also excludes; per-handler raw serde; one fan-out
ordering case; in-flight deployment upgrade; V7 scoped concurrency / virtual queues). See
`../docs/adr/0001-cancellation-over-bidirectional-streaming.md`.

## Run it

Requires **JDK ≥ 21** + Docker, and an **AVX2** host (the suite's Restate ≥ 1.6 runtime
SIGILLs on older CPUs — see the fallback below). `make conformance` downloads the suite
jar, builds the V7-enabled runtime (`Dockerfile.restate-v7`) and the bidi service image
(`Dockerfile.amp`), and runs the `default` config:

```bash
make conformance                       # the default config, bidi V7
make conformance TEST_SUITE=all        # every configuration
JAVA=/path/to/jdk21/bin/java make conformance   # if `java` is < 21

# or a single class while debugging:
java -jar build/sdk-tests.jar run \
  --restate-container-image=localhost/restatedev/restate-v7:latest \
  --service-container-image=localhost/restatedev/php-amp-test-services:latest \
  --test-suite=default --test-name=Cancellation \
  --exclusions-file=conformance/exclusions.yaml \
  --image-pull-policy=CACHED --report-dir=build/conformance-report --sequential
```

`--sequential` is used because parallel container startup makes the runtime's h2c
discovery handshake flaky on a single host. The PHP server speaks HTTP/2 cleartext (h2c),
which the runtime uses for discovery + bidirectional invocation.

The runtime is built from `Dockerfile.restate-v7` (stock Restate + the
`experimental-enable-protocol-v7` flag): Restate 1.7.0 supports V7 but negotiates V6 by
default, on which the SDK's signal/awakeable model is invalid.

After a run, `build/conformance-report/<ts>/exclusions.new.yaml` lists everything that
failed/was skipped — copy entries into `exclusions.yaml` to baseline new gaps.

## CI

`.github/workflows/conformance.yml` runs the same `make conformance` on pushes to `main`,
weekly, on demand (`workflow_dispatch`), and on PRs labelled `conformance`. GitHub runners
have AVX2, so the ≥ 1.6 runtime works there. The suite is too heavy to gate every PR, so it
is not in the main `CI` workflow.

## Offline fallback (AVX2-free hosts)

The new suite's runtime needs AVX2. On an AVX2-free host, run the **archived**
[`restatedev/sdk-test-suite`](https://github.com/restatedev/sdk-test-suite) `v4.1` jar
against the **request/response Swoole** image (`conformance/Dockerfile` →
`php-test-services`) on the last AVX2-free runtime, **Restate 1.5.2**:

```bash
curl -fSL -o build/restate-sdk-test-suite.jar \
  https://github.com/restatedev/sdk-test-suite/releases/download/v4.1/restate-sdk-test-suite.jar
docker build -f conformance/Dockerfile -t localhost/restatedev/php-test-services:latest .
java -jar build/restate-sdk-test-suite.jar run \
  --restate-container-image=docker.io/restatedev/restate:1.5.2 \
  --test-suite=default --sequential \
  --report-dir=build/conformance-report \
  localhost/restatedev/php-test-services:latest
```

This path serves request/response, so the V7-only cases (`Cancellation`, `KillInvocation`,
`Signals`) cannot pass and must be excluded — generate a fallback exclusions file from the
run's `exclusions.new.yaml` rather than reusing the bidi `exclusions.yaml`. It is frozen
(no new-runtime compatibility), but runs where the maintained suite's runtime will not.
