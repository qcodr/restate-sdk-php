# Restate PHP SDK deployment image (bidirectional streaming variant): plain PHP 8.4
# CLI + amphp/http-server (NO ext-swoole), serving the example services over HTTP/2
# (h2c) on port 9080 for the Restate runtime to discover and invoke.
#
# The request/response Swoole image lives at docker/php/Dockerfile and is unaffected.
FROM php:8.4-cli

# amphp uses non-blocking sockets and benefits from an event-loop extension; ext-pcntl
# provides the signal handling AmpStreamingServer::listen() traps for graceful shutdown.
RUN docker-php-ext-install pcntl

WORKDIR /app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Install incl. the amphp host (a require-dev / suggest dependency); --no-dev would skip
# amphp/http-server, so dev deps are needed for this streaming image.
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress --optimize-autoloader

COPY src ./src
COPY examples ./examples
RUN composer dump-autoload --optimize

EXPOSE 9080

CMD ["php", "examples/amp-endpoint.php"]
