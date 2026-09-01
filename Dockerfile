# PHP 8.2+ SDK image with grpc preinstalled (avoids PECL compile OOM on Docker Desktop).
FROM clegginabox/php-grpc:8.2-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip curl protobuf-compiler \
    && rm -rf /var/lib/apt/lists/*

# Optional: grpc_php_plugin for regenerate; compile-proto.sh falls back if missing.
RUN apt-get update \
    && apt-get install -y --no-install-recommends protobuf-compiler-grpc \
    && rm -rf /var/lib/apt/lists/* \
    || true

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    PULSEINDEX_HOST=host.docker.internal:50051 \
    PULSEINDEX_API_KEY=dev-key

COPY composer.json ./
# No lockfile yet — resolve deps inside the image (ext-grpc already enabled).
RUN composer update --no-interaction --prefer-dist --no-scripts

COPY . .

RUN composer dump-autoload --optimize

CMD ["vendor/bin/phpunit"]
