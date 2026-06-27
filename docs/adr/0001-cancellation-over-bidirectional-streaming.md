# ADR 0001 — Cancellation over the bidirectional (HTTP/2) streaming transport

**Status:** Accepted

## Context

The SDK gained an AMPHP HTTP/2 bidirectional streaming transport (`AmpStreamingServer`)
alongside the original request/response Swoole server. The cross-SDK conformance suite's
`Cancellation.*` and `KillInvocation.kill` classes failed (0/6 and 0/1) and were excluded.

Cancelling a *parked* invocation is the hard case: the runtime must wake the invocation to
deliver the built-in CANCEL signal, the handler's pending await must fail, and the cancel
must propagate so the invocation's children and locks are released. Getting this right
turned out to require the **service protocol V7** model end to end, plus several SDK-side
corrections that the conformance suite exercises but unit tests did not.

## Decision

Implement cancellation against **service protocol V7**, the version the SDK already targets
(signals, signal-backed awakeables, the Future-based `SuspensionMessage`, and
`AwaitingOnMessage`). Concretely:

1. **Require a V7-capable runtime.** Restate 1.7.0 supports V7 but negotiates **V6 by
   default**; on V6 the SDK's signal/awakeable model is invalid (an awakeable resolution is
   applied as a non-existent completion index and crashes the partition). V7 is enabled with
   the runtime flag `experimental-enable-protocol-v7` (env
   `RESTATE_EXPERIMENTAL_ENABLE_PROTOCOL_V7=true`). The bidi conformance uses
   `conformance/Dockerfile.restate-v7`, which bakes that flag onto `restate:latest`.

2. **Signal-backed awakeable id.** Awakeable ids use the `sign_1` prefix (signal-backed),
   not the legacy completion-backed `prom_1` (`src/Vm/AwakeableId.php`).

3. **Announce await points.** On every streaming park the SDK emits an `AwaitingOnMessage`
   (`FiberSuspender` → `StateMachine::writeAwaitingOn`) so the runtime knows what a parked
   invocation awaits and pushes the matching completion/signal onto the open stream.

4. **Canonical cancel-guard await tree.** A single-leaf await flattens its ids next to the
   CANCEL signal under a `FirstCompleted` node (`StateMachine::guardWithCancel`) — the flat
   shape the runtime keys its cancel wake-up off — rather than nesting the await beneath a
   top-level signal.

5. **Implicit cancellation propagation.** A handler tracks the invocation ids of the calls
   it issues and, when cancelled at an await, sends the CANCEL signal to each known child
   before failing with 409 (`StateMachine::raiseCancellation`). A cancelled parent therefore
   tears down the calls it was blocked on, so children release their virtual-object locks.

6. **Raised amphp connection limits.** The runtime opens one long-lived bidi connection per
   in-flight invocation, all from one IP; amphp's defaults (1000 total, 10 per IP, 1000
   concurrent) starve under load ("too many existing connections"). The limits are raised so
   the runtime governs concurrency (`AmpStreamingServer`).

Two supporting streaming-driver fixes also landed: the await/cancel combinators raise
`CancelledException` (409) when woken only by a cancel, and the driver drains every
already-resolvable park per inbound chunk rather than suspending spuriously.

## Consequences

- Against a V7 runtime over bidi: `Cancellation` 6/6, `KillInvocation` 1/1, with no
  regression — `State` 3/3 and `ServiceToServiceCommunication` 5/5 (the latter previously
  4/5; V7 also fixed `oneWayCallWithDelay`). The exclusions are removed
  (`conformance/exclusions.yaml`).
- The bidi transport now **requires a V7-enabled runtime** for awakeables and cancellation.
  Against a default (V6) runtime those features do not work; basic features still do.
- The request/response (Swoole) transport is unchanged and not covered by this ADR.
