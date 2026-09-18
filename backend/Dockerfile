# Development image for the HRIS API (see compose.yaml at the repo root).
# The source is bind-mounted at runtime, so nothing from the project is baked
# in except the entrypoint.
FROM php:8.4-cli-bookworm

# pdo_mysql for MySQL; pdo_sqlite ships with the base image and backs the test
# suite (in-memory). openssl, used by the crypto verifiers, is built in too.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev libicu-dev \
    && docker-php-ext-install pdo_mysql bcmath intl zip pcntl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/entrypoint.sh /usr/local/bin/hris-entrypoint
# Strip CR in case a Windows checkout gave the script CRLF line endings.
RUN sed -i 's/\r$//' /usr/local/bin/hris-entrypoint && chmod +x /usr/local/bin/hris-entrypoint

WORKDIR /var/www/html
EXPOSE 8090

ENTRYPOINT ["hris-entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8090", "--no-reload"]
