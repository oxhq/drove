FROM composer:2@sha256:5946476338742b200bb9ff88f8be56275ddae4b3949c72305cb0dbf10cfcb760 AS composer
FROM php:8.4-cli@sha256:3a70cc45c4b03b691f04ea3c032030613a5ff6ee61053127aadb8a93b39cc7c3

RUN apt-get update \
    && apt-get install -y --no-install-recommends libffi-dev unzip \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install ffi pcntl
COPY --from=composer /usr/bin/composer /usr/bin/composer

COPY . /pest

WORKDIR /app

COPY experiments/phase-1/composer.* ./
RUN composer install --no-interaction --no-progress --prefer-dist --optimize-autoloader

COPY experiments/phase-3/ ./

CMD ["php", "spike.php"]
