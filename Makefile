# Restate PHP SDK — developer tasks.
#
# Unit tests run anywhere (no extensions needed). End-to-end verification is the
# official cross-SDK conformance suite (`make conformance`); `make examples` brings
# up a live Restate + the example services to try by hand.

INGRESS_URL ?= http://localhost:8080
ADMIN_URL   ?= http://localhost:9070

.PHONY: install test test-unit up wait down logs lint stan cs cs-fix sast infection bench bench-e2e bench-e2e-amp bench-e2e-compare check examples

install:
	composer install

test: test-unit

test-unit:
	vendor/bin/phpunit --testsuite unit

# --- Static analysis & coding standard ------------------------------------

# Full strict gate: coding standard (check only) + static analysis.
lint: cs stan

# PHPStan at max level over src, tests and examples.
stan:
	vendor/bin/phpstan analyse --no-progress

# Verify coding standard without modifying files (fails on violations).
cs:
	vendor/bin/php-cs-fixer fix --dry-run --diff

# Apply the coding standard.
cs-fix:
	vendor/bin/php-cs-fixer fix

# Offline SAST: Psalm taint analysis (untrusted input -> dangerous sinks).
sast:
	vendor/bin/psalm --taint-analysis --no-progress

# Mutation testing (Infection): the share of injected faults the tests catch.
# Needs a coverage driver (pcov/xdebug); fails below the thresholds in infection.json5.dist.
infection:
	vendor/bin/infection --threads=max --no-progress

# --- Benchmarks (see docs/BENCHMARKS.md) -----------------------------------

# SDK hot-path micro-benchmark: pure PHP, no I/O, reproducible anywhere.
bench:
	php benchmarks/micro.php

# End-to-end load/latency/memory through Restate (needs Docker). Swoole request/response
# by default; `bench-e2e-amp` runs the amphp bidi transport; `bench-e2e-compare` runs
# both against one runtime and prints them side by side.
bench-e2e:
	TRANSPORT=swoole benchmarks/e2e/run.sh

bench-e2e-amp:
	TRANSPORT=amp benchmarks/e2e/run.sh

bench-e2e-compare:
	benchmarks/e2e/compare.sh

# The local pre-commit gate: lint + SAST + unit tests.
check: lint sast test-unit

up:
	docker compose up -d --build

wait:
	@echo "Waiting for Restate admin & ingress to become healthy..."
	@for i in $$(seq 1 60); do \
		if curl -fsS $(ADMIN_URL)/health >/dev/null 2>&1 && curl -fsS $(INGRESS_URL)/restate/health >/dev/null 2>&1; then \
			echo "Restate is healthy."; exit 0; \
		fi; \
		sleep 2; \
	done; \
	echo "Restate did not become healthy in time" >&2; exit 1

# Bring up the example services live (all examples on one endpoint, over true bidi
# HTTP/2 via amphp) and register them. No `use_http_11`: bidi requires HTTP/2, which the
# runtime negotiates against the amphp h2c host. The pinned 1.5.2 already serves bidi;
# override with RESTATE_IMAGE=... for a newer runtime if needed.
examples:
	docker compose up -d --build restate examples-endpoint
	$(MAKE) wait
	@curl -fsS -X POST $(ADMIN_URL)/deployments \
		-H 'content-type: application/json' \
		-d '{"uri":"http://examples-endpoint:9080","force":true}' >/dev/null \
		&& echo "Examples registered. Try: curl $(INGRESS_URL)/FanOut/fanOut"

# --- Cross-SDK conformance (official restatedev/e2e sdk-tests) ------------
# The primary path runs the actively-maintained restatedev/e2e suite against the
# bidi (amphp) transport on a V7-enabled runtime — the SDK's default transport,
# where Cancellation / KillInvocation / Signals pass. Needs JDK >= 21 and an AVX2
# host (Restate >= 1.6). The archived sdk-test-suite v3.4 + Restate 1.5.2 offline
# fallback for AVX2-free hosts is documented in conformance/README.md.
SUITE_VERSION    ?= v2.2
SUITE_JAR        ?= build/sdk-tests.jar
SUITE_URL        := https://github.com/restatedev/e2e/releases/download/$(SUITE_VERSION)/sdk-tests.jar
JAVA             ?= java
RESTATE_V7_IMAGE ?= localhost/restatedev/restate-v7:latest
PHP_TS_IMAGE     ?= localhost/restatedev/php-amp-test-services:latest
TEST_SUITE       ?= default
EXCLUSIONS       ?= conformance/exclusions.yaml
REPORT_DIR       ?= build/conformance-report

.PHONY: conformance conformance-image conformance-jar conformance-run

conformance-jar:
	@test -f $(SUITE_JAR) || (mkdir -p $(dir $(SUITE_JAR)) && curl -fSL -o $(SUITE_JAR) $(SUITE_URL))

# Build the V7-enabled runtime (stock Restate + the experimental-protocol-v7 flag) and the
# bidi (amphp) test-services image. Both are tagged localhost/ so the suite uses them from
# cache (--image-pull-policy=CACHED) instead of pulling.
conformance-image:
	docker build -f conformance/Dockerfile.restate-v7 -t $(RESTATE_V7_IMAGE) .
	docker build -f conformance/Dockerfile.amp -t $(PHP_TS_IMAGE) .

# Full run: download suite, build the runtime + service images, run the chosen config(s).
conformance: conformance-jar conformance-image conformance-run

conformance-run:
	$(JAVA) -jar $(SUITE_JAR) run \
		--restate-container-image=$(RESTATE_V7_IMAGE) \
		--service-container-image=$(PHP_TS_IMAGE) \
		--test-suite=$(TEST_SUITE) \
		--sequential \
		$(if $(wildcard $(EXCLUSIONS)),--exclusions-file=$(EXCLUSIONS),) \
		--image-pull-policy=CACHED \
		--report-dir=$(REPORT_DIR)
	@echo "Report + exclusions.new.yaml under $(REPORT_DIR)/"

logs:
	docker compose logs --no-color

down:
	docker compose down -v
