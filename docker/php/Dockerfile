# Restate PHP SDK deployment image: PHP 8.x + ext-swoole, serving the example
# services on port 9080 for the Restate runtime to discover and invoke.
FROM phpswoole/swoole:latest

WORKDIR /app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Install (no dev deps needed at runtime) to generate the autoloader.
COPY composer.json ./
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader

COPY src ./src
COPY examples ./examples
RUN composer dump-autoload --no-dev --optimize

EXPOSE 9080

CMD ["php", "examples/endpoint.php"]
