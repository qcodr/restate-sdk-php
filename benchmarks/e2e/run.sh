#!/usr/bin/env bash
#
# End-to-end load / latency / memory benchmark.
#
# Drives a real request path: oha -> Restate ingress -> the runtime -> the PHP Swoole
# endpoint (this SDK) -> response. Latency and throughput come from oha; the Swoole
# worker's resident memory is sampled with `docker stats` during a sustained run to
# detect leaks. Everything is containerized — the only host requirement is Docker.
#
# Usage:
#   benchmarks/e2e/run.sh
#   DURATION=60s CONNECTIONS=100 benchmarks/e2e/run.sh
#   KEEP_UP=1 benchmarks/e2e/run.sh        # leave the stack running afterwards
#
# Env:
#   DURATION     load duration per oha run            (default 30s)
#   CONNECTIONS  concurrent connections               (default 50)
#   HANDLER      ingress path to hit                  (default /Greeter/greet)
#   BODY         JSON request body                    (default "world")
#   INGRESS      ingress base URL                     (default http://localhost:8080)
#   OHA_IMAGE    load-generator image                 (default ghcr.io/hatoo/oha:latest)

set -euo pipefail

DURATION="${DURATION:-30s}"
MEM_DURATION="${MEM_DURATION:-180s}"   # longer window so the leak slope is post-warmup
CONNECTIONS="${CONNECTIONS:-50}"
HANDLER="${HANDLER:-/Greeter/greet}"
BODY="${BODY:-\"world\"}"
INGRESS="${INGRESS:-http://localhost:8080}"
ADMIN="${ADMIN:-http://localhost:9070}"
OHA_IMAGE="${OHA_IMAGE:-ghcr.io/hatoo/oha:latest}"
ENDPOINT_SERVICE="examples-endpoint"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
OUT_DIR="$ROOT/build/benchmarks"
mkdir -p "$OUT_DIR"
cd "$ROOT"

log() { printf '\n=== %s ===\n' "$*"; }

cleanup() {
    if [ "${KEEP_UP:-0}" != "1" ]; then
        log "Tearing down"
        docker compose down -v >/dev/null 2>&1 || true
    else
        echo "KEEP_UP=1 — stack left running (docker compose down -v to stop)."
    fi
}
trap cleanup EXIT

log "Bringing up Restate 1.5.2 + Swoole endpoint"
docker compose up -d --build restate "$ENDPOINT_SERVICE"

log "Waiting for health"
for _ in $(seq 1 60); do
    if curl -fsS "$ADMIN/health" >/dev/null 2>&1 && curl -fsS "$INGRESS/restate/health" >/dev/null 2>&1; then
        echo "healthy"; break
    fi
    sleep 2
done

log "Registering deployment"
curl -fsS -X POST "$ADMIN/deployments" \
    -H 'content-type: application/json' \
    -d "{\"uri\":\"http://$ENDPOINT_SERVICE:9080\",\"use_http_11\":true,\"force\":true}" >/dev/null
echo "registered"

log "Warmup"
for _ in $(seq 1 20); do
    curl -fsS -X POST "$INGRESS$HANDLER" -H 'content-type: application/json' -d "$BODY" >/dev/null || true
done

docker pull -q "$OHA_IMAGE" >/dev/null

log "Load: oha -z $DURATION -c $CONNECTIONS POST $HANDLER"
# --network host: reach the published ingress port on localhost (Linux).
docker run --rm --network host "$OHA_IMAGE" \
    -z "$DURATION" -c "$CONNECTIONS" --no-tui --output-format json \
    -m POST -d "$BODY" -H 'content-type: application/json' \
    "$INGRESS$HANDLER" > "$OUT_DIR/e2e-oha.json"

# Sustained load in the background while we sample the worker's resident memory.
log "Memory sampling under sustained load (leak check)"
CID="$(docker compose ps -q "$ENDPOINT_SERVICE")"   # compose prefixes the container name
docker run --rm --network host "$OHA_IMAGE" \
    -z "$MEM_DURATION" -c "$CONNECTIONS" --no-tui --output-format json \
    -m POST -d "$BODY" -H 'content-type: application/json' \
    "$INGRESS$HANDLER" >/dev/null 2>&1 &
LOAD_PID=$!

: > "$OUT_DIR/e2e-mem.csv"
echo "seconds,mem_bytes" >> "$OUT_DIR/e2e-mem.csv"
SECONDS_ELAPSED=0
while kill -0 "$LOAD_PID" 2>/dev/null; do
    USAGE="$(docker stats --no-stream --format '{{.MemUsage}}' "$CID" 2>/dev/null | awk '{print $1}')" || USAGE=""
    BYTES="$(python3 - "$USAGE" <<'PY'
import sys, re
s = sys.argv[1] if len(sys.argv) > 1 else ""
m = re.match(r"([0-9.]+)\s*([KMGT]?i?B)", s)
mult = {"B":1,"KiB":1024,"MiB":1024**2,"GiB":1024**3,"TiB":1024**4,"KB":1000,"MB":1000**2,"GB":1000**3}
print(int(float(m.group(1))*mult.get(m.group(2),1)) if m else 0)
PY
)" || BYTES=0
    echo "$SECONDS_ELAPSED,$BYTES" >> "$OUT_DIR/e2e-mem.csv"
    sleep 3
    SECONDS_ELAPSED=$((SECONDS_ELAPSED+3))
done
wait "$LOAD_PID" 2>/dev/null || true

log "Summary"
python3 - "$OUT_DIR/e2e-oha.json" "$OUT_DIR/e2e-mem.csv" <<'PY'
import json, sys
oha = json.load(open(sys.argv[1]))
s = oha.get("summary", {})
p = oha.get("latencyPercentiles", {})
print(f"  requests/sec : {s.get('requestsPerSec', 0):,.0f}")
print(f"  success rate : {s.get('successRate', 0)*100:.2f}%")
print(f"  latency p50  : {p.get('p50', 0)*1000:.2f} ms")
print(f"  latency p90  : {p.get('p90', 0)*1000:.2f} ms")
print(f"  latency p99  : {p.get('p99', 0)*1000:.2f} ms")
print(f"  latency max  : {s.get('slowest', 0)*1000:.2f} ms")

rows = [l.strip().split(",") for l in open(sys.argv[2]).read().splitlines()[1:] if l.strip()]
mem = [(int(t), int(b)) for t, b in rows if b.isdigit() and int(b) > 0]
if len(mem) >= 2:
    # Slope over the steady-state window (ignore the first 30s of warmup: opcache,
    # Swoole connection buffers) so the leak verdict reflects sustained behavior.
    cutoff = min(30, mem[-1][0] // 2)
    steady = [m for m in mem if m[0] >= cutoff] or mem
    # Least-squares slope over the steady window: robust to docker-stats' ~0.1 MiB
    # quantization, unlike a two-point first/last difference.
    n = len(steady)
    mt = sum(t for t, _ in steady) / n
    mb = sum(b for _, b in steady) / n
    denom = sum((t - mt) ** 2 for t, _ in steady) or 1
    slope = sum((t - mt) * (b - mb) for t, b in steady) / denom * 60 / 1e6  # MB/min
    peak = max(b for _, b in mem)
    span = steady[-1][0] - steady[0][0]
    print(f"  worker RSS   : start {mem[0][1]/1e6:.1f} MB, peak {peak/1e6:.1f} MB")
    print(f"  steady slope : {slope:+.2f} MB/min (least-squares over {span}s, n={n}) "
          f"({'no leak' if abs(slope) < 0.5 else 'investigate'})")
PY

echo
echo "Raw: $OUT_DIR/e2e-oha.json, $OUT_DIR/e2e-mem.csv"
