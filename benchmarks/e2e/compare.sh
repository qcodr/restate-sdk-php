#!/usr/bin/env bash
#
# Runs the e2e benchmark for BOTH transports against the SAME runtime and prints a
# side-by-side comparison (Swoole request/response vs amphp bidi HTTP/2). Both legs run
# against one runtime image for a fair comparison; the pinned 1.5.2 serves bidi fine.
# Override with RESTATE_IMAGE for a newer runtime.
#
# Usage:
#   benchmarks/e2e/compare.sh
#   DURATION=60s CONNECTIONS=100 benchmarks/e2e/compare.sh
#   RESTATE_IMAGE=docker.io/restatedev/restate:1.7.0 benchmarks/e2e/compare.sh

set -euo pipefail

export RESTATE_IMAGE="${RESTATE_IMAGE:-docker.io/restatedev/restate:1.5.2}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
RUN="$ROOT/benchmarks/e2e/run.sh"
OUT_DIR="$ROOT/build/benchmarks"

echo "### Runtime: $RESTATE_IMAGE"
TRANSPORT=swoole "$RUN"
TRANSPORT=amp    "$RUN"

echo
echo "=== Swoole (r/r) vs amphp (bidi) ==="
python3 - "$OUT_DIR/e2e-oha-swoole.json" "$OUT_DIR/e2e-oha-amp.json" <<'PY'
import json, sys

def load(path):
    o = json.load(open(path))
    s, p = o.get("summary", {}), o.get("latencyPercentiles", {})
    return {
        "req/s": s.get("requestsPerSec", 0),
        "ok%": s.get("successRate", 0) * 100,
        "p50ms": p.get("p50", 0) * 1000,
        "p90ms": p.get("p90", 0) * 1000,
        "p99ms": p.get("p99", 0) * 1000,
    }

sw, amp = load(sys.argv[1]), load(sys.argv[2])
cols = ["req/s", "ok%", "p50ms", "p90ms", "p99ms"]
print(f"{'metric':<8}{'swoole':>14}{'amp':>14}{'amp/swoole':>12}")
for c in cols:
    ratio = (amp[c] / sw[c]) if sw[c] else 0
    print(f"{c:<8}{sw[c]:>14,.2f}{amp[c]:>14,.2f}{ratio:>11.2f}x")
PY
