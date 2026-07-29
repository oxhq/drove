FROM composer:2@sha256:5946476338742b200bb9ff88f8be56275ddae4b3949c72305cb0dbf10cfcb760 AS composer
FROM rust:1.88-bookworm@sha256:af306cfa71d987911a781c37b59d7d67d934f49684058f96cf72079c3626bfe0 AS drover-builder

WORKDIR /build

COPY native/drover/Cargo.toml native/drover/Cargo.lock ./
COPY native/drover/src ./src
COPY native/drover/protocol ./protocol

RUN cargo build --release --locked

FROM php:8.4-cli@sha256:3a70cc45c4b03b691f04ea3c032030613a5ff6ee61053127aadb8a93b39cc7c3

RUN apt-get update \
    && apt-get install -y --no-install-recommends libffi-dev unzip \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install ffi pcntl \
    && pecl install pcov-1.0.12 \
    && docker-php-ext-enable pcov
COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY --from=drover-builder /build/target/release/libdrover.so /usr/local/lib/libdrover.so

COPY . /drove

ENV DROVER_LIBRARY=/usr/local/lib/libdrover.so

WORKDIR /app

COPY experiments/phase-2-cli/composer.* ./
RUN composer install --no-interaction --no-progress --prefer-dist --optimize-autoloader

COPY experiments/phase-2-cli/project/ ./

CMD ["php", "proof.php"]
