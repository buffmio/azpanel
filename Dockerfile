FROM php:8.2-fpm-bookworm

ARG SUPERCRONIC_VERSION=v0.2.29

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        libicu-dev \
        libonig-dev \
        libpng-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-install bcmath gd intl mbstring pcntl pdo_mysql sockets zip \
    && curl -fsSL -o /usr/local/bin/supercronic https://github.com/aptible/supercronic/releases/download/${SUPERCRONIC_VERSION}/supercronic-linux-amd64 \
    && chmod +x /usr/local/bin/supercronic \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY . /app

RUN composer install --no-dev --optimize-autoloader \
    && chmod +x /app/think /app/docker/entrypoint.sh \
    && mkdir -p /app/runtime \
    && chown -R www-data:www-data /app/runtime

EXPOSE 9000

ENTRYPOINT ["/app/docker/entrypoint.sh"]

