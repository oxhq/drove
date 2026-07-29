FROM drove-phase-three-laravel:latest

ARG DROVE_REVISION=unknown

RUN apt-get update \
    && apt-get install -y --no-install-recommends git libicu-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install intl sockets

WORKDIR /drove

COPY tests ./tests

RUN COMPOSER_ROOT_VERSION=0.4.0-alpha.1 \
    composer install --no-interaction --no-progress --prefer-dist --optimize-autoloader

RUN printf '%s\n' "$DROVE_REVISION" >/usr/local/share/drove-revision
