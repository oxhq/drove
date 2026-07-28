FROM composer:2@sha256:5946476338742b200bb9ff88f8be56275ddae4b3949c72305cb0dbf10cfcb760 AS composer
FROM php:8.4-cli@sha256:3a70cc45c4b03b691f04ea3c032030613a5ff6ee61053127aadb8a93b39cc7c3

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pcntl
COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.* ./
RUN composer install --no-interaction --no-progress --prefer-dist --optimize-autoloader

COPY . .

CMD ["php", "spike.php"]
