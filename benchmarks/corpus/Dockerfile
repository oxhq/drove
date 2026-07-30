FROM node:22-bookworm-slim@sha256:f32b81066cde10a75dbac96646099533316d94bac4150c55da1636e1f0ffdc46 AS node
FROM drove-phase-three-laravel:latest

ARG DROVE_REVISION=unknown

COPY --from=node /usr/local/bin/node /usr/local/bin/
COPY --from=node /usr/local/lib/node_modules/npm /usr/local/lib/node_modules/npm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git libicu-dev \
    && ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && git config --system --add safe.directory /app \
    && git config --system --add safe.directory /workspace/pest \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install intl sockets

WORKDIR /drove

COPY tests ./tests

RUN COMPOSER_ROOT_VERSION=0.4.0-alpha.1 \
    composer install --no-interaction --no-progress --prefer-dist --optimize-autoloader

RUN printf '%s\n' "$DROVE_REVISION" >/usr/local/share/drove-revision
