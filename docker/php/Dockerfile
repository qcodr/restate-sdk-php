# Restate PHP SDK Swoole image: PHP 8.x + ext-swoole, serving over request/response
# on port 9080. This is the ALTERNATIVE transport — the default deployment image is the
# amphp bidirectional-streaming one at docker/php-amp/Dockerfile. Kept here because the
# Swoole e2e benchmark (benchmarks/e2e, TRANSPORT=swoole) measures this request/response
# path against the amphp one.
FROM phpswoole/swoole:latest

WORKDIR /app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Install (no dev deps needed at runtime) to generate the autoloader.
COPY composer.json ./
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader

COPY src ./src
COPY examples ./examples
COPY benchmarks ./benchmarks
RUN composer dump-autoload --no-dev --optimize

EXPOSE 9080

CMD ["php", "benchmarks/e2e/bench-endpoint.php"]
