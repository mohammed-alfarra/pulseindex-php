# PHP 8.2+ SDK image with grpc + protobuf PECL extensions.
FROM php:8.2-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        curl \
        $PHPIZE_DEPS \
        zlib1g-dev \
        libssl-dev \
        protobuf-compiler \
    && pecl install grpc protobuf \
    && docker-php-ext-enable grpc protobuf \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# grpc_php_plugin is optional for regenerate; messages ship in generated/.
# Install when available via distro packages; otherwise compile-proto.sh falls back.
RUN apt-get update \
    && apt-get install -y --no-install-recommends protobuf-compiler-grpc \
    && rm -rf /var/lib/apt/lists/* \
    || true

WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    PULSEINDEX_HOST=pulseindex-engine:50051 \
    PULSEINDEX_API_KEY=dev-key

COPY composer.json ./
# No lockfile yet — resolve deps inside the image (ext-grpc / ext-protobuf already enabled).
RUN composer update --no-interaction --prefer-dist --no-scripts

COPY . .

RUN composer dump-autoload --optimize

CMD ["vendor/bin/phpunit"]
