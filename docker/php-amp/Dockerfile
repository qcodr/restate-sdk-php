# Restate PHP SDK deployment image (DEFAULT): plain PHP 8.4 CLI + amphp/http-server
# (NO ext-swoole), serving the example services over true bidirectional HTTP/2 (h2c)
# on port 9080 for the Restate runtime to discover and invoke.
#
# This is the default server (AmpStreamingServer). The Swoole request/response variant
# lives at docker/php/Dockerfile and is used by the Swoole e2e benchmark.
FROM php:8.4-cli

# git + unzip let Composer extract dist archives (the slim php:8.4-cli image ships neither,
# unlike phpswoole/swoole). ext-pcntl provides the signal handling AmpStreamingServer::listen()
# traps for graceful shutdown; mbstring + sodium are already bundled in php:8.4-cli, and amphp
# uses non-blocking sockets so no event-loop extension is required.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pcntl

WORKDIR /app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# amphp/http-server is a require-dev / suggest dependency. Pull ONLY it (plus the prod
# deps) rather than the full require-dev tooling (phpunit/psalm/infection/…) a plain
# `composer install` would drag into the runtime image — smaller image, and far fewer
# GitHub downloads to flake on. `--update-no-dev` resolves without the dev tooling;
# composer.lock is gitignored for a library, so deps resolve fresh from composer.json.
COPY composer.json ./
RUN composer require amphp/http-server:^3 \
        --no-interaction --no-progress --optimize-autoloader --update-no-dev

COPY src ./src
COPY examples ./examples
COPY benchmarks ./benchmarks
RUN composer dump-autoload --optimize

EXPOSE 9080

CMD ["php", "examples/endpoint.php"]
