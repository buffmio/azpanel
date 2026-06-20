FROM php:8.3-fpm-bookworm

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    APP_HOME=/var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        cron \
        git \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libzip-dev \
        supervisor \
        unzip \
        zip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        curl \
        gd \
        mbstring \
        mysqli \
        pcntl \
        pdo_mysql \
        sockets \
        zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR ${APP_HOME}

COPY composer.json ./
RUN composer install --no-dev --no-scripts --prefer-dist --optimize-autoloader

COPY . .
COPY docker/php/php.ini /usr/local/etc/php/conf.d/azpanel.ini
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/cron/azpanel /etc/cron.d/azpanel

RUN chmod 0644 /etc/cron.d/azpanel \
    && crontab /etc/cron.d/azpanel \
    && mkdir -p runtime storage backups \
    && chown -R www-data:www-data ${APP_HOME}

EXPOSE 9000

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
