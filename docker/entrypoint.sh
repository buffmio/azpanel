#!/bin/sh
set -e

if [ ! -f /app/.env ]; then
    echo "未找到 /app/.env，请挂载或复制 .env 后再启动容器。"
    exit 1
fi

mkdir -p /app/runtime
chown -R www-data:www-data /app/runtime

supercronic /app/docker/supercronic.cron &

exec php-fpm
