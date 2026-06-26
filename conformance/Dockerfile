# Cross-SDK conformance test-services image (PHP + ext-swoole).
# Built by `make conformance` and driven by restatedev/sdk-test-suite.
FROM phpswoole/swoole:latest

WORKDIR /app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json ./
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader

COPY src ./src
COPY conformance ./conformance
RUN composer dump-autoload --no-dev --optimize

EXPOSE 9080

CMD ["php", "conformance/main.php"]
