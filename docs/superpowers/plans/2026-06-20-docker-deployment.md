# Docker Deployment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an interactive Docker deployment path for azpanel using Nginx, PHP-FPM with in-container cron, and `mariadb:10.11-jammy`.

**Architecture:** Docker Compose runs three services: `web`, `app`, and `db`. The `app` image contains PHP-FPM, Composer dependencies, cron, and supervisor; Nginx serves `public/` and forwards PHP to `app:9000`; MariaDB data is stored in a named volume. `deploy.sh` generates environment/config files, performs install/redeploy/uninstall/purge/renew/backup operations, and initializes the database explicitly.

**Tech Stack:** Docker Compose, PHP 8.3 FPM, Nginx Alpine, MariaDB 10.11 Jammy, Supervisor, cron, Bash.

---

## File Structure

- Create `Dockerfile`: build the PHP-FPM app image with extensions, Composer dependencies, supervisor, and cron.
- Create `.dockerignore`: keep build context small and exclude secrets/runtime outputs.
- Create `docker-compose.yml`: define `web`, `app`, and `db` services.
- Create `docker/php/php.ini`: production PHP settings and required enabled functions.
- Create `docker/supervisor/supervisord.conf`: run PHP-FPM and cron in the app container.
- Create `docker/cron/azpanel`: cron schedule copied into the app image.
- Create `docker/nginx/http.conf`: generated-runtime compatible HTTP Nginx config template.
- Create `docker/nginx/https.conf`: generated-runtime compatible HTTPS Nginx config template.
- Create `deploy.sh`: interactive deployment, redeploy, uninstall, purge, certificate renewal, and database backup script.
- Create `docs/docker-deploy.md`: Chinese deployment guide.

## Task 1: Add Container Runtime Files

**Files:**
- Create: `Dockerfile`
- Create: `.dockerignore`
- Create: `docker/php/php.ini`
- Create: `docker/supervisor/supervisord.conf`
- Create: `docker/cron/azpanel`

- [ ] **Step 1: Add `.dockerignore`**

Create `.dockerignore` with:

```dockerignore
.git
.idea
.vscode
.env
.docker.env
vendor
runtime/*
!runtime/.gitignore
storage/logs
docker/runtime
docker/certs
docker/certbot
backups
*.log
```

- [ ] **Step 2: Add PHP config**

Create `docker/php/php.ini` with:

```ini
date.timezone = Asia/Shanghai
memory_limit = 512M
upload_max_filesize = 50M
post_max_size = 50M
max_execution_time = 300
max_input_time = 300
display_errors = Off
log_errors = On
error_log = /proc/self/fd/2
expose_php = Off
disable_functions =
```

- [ ] **Step 3: Add cron schedule**

Create `docker/cron/azpanel` with:

```cron
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

0 0 * * * www-data cd /var/www/html && php think tools --action statisticsTraffic >> /proc/1/fd/1 2>> /proc/1/fd/2
0 * * * * www-data cd /var/www/html && php think autoRefreshAccount >> /proc/1/fd/1 2>> /proc/1/fd/2
0 * * * * www-data cd /var/www/html && php think closeTimeoutTask >> /proc/1/fd/1 2>> /proc/1/fd/2
0 * * * * www-data cd /var/www/html && php think trafficControlStop >> /proc/1/fd/1 2>> /proc/1/fd/2
*/5 * * * * www-data cd /var/www/html && php think trafficControlStart >> /proc/1/fd/1 2>> /proc/1/fd/2
```

- [ ] **Step 4: Add supervisor config**

Create `docker/supervisor/supervisord.conf` with:

```ini
[supervisord]
nodaemon=true
logfile=/dev/null
logfile_maxbytes=0
pidfile=/tmp/supervisord.pid

[program:php-fpm]
command=php-fpm -F
autostart=true
autorestart=true
priority=10
stdout_logfile=/dev/fd/1
stdout_logfile_maxbytes=0
stderr_logfile=/dev/fd/2
stderr_logfile_maxbytes=0
stopasgroup=true
killasgroup=true

[program:cron]
command=cron -f
autostart=true
autorestart=true
priority=20
stdout_logfile=/dev/fd/1
stdout_logfile_maxbytes=0
stderr_logfile=/dev/fd/2
stderr_logfile_maxbytes=0
stopasgroup=true
killasgroup=true
```

- [ ] **Step 5: Add Dockerfile**

Create `Dockerfile` with:

```dockerfile
FROM php:8.3-fpm-bookworm

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    APP_HOME=/var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        cron \
        git \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libcurl4-openssl-dev \
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
    && mkdir -p runtime storage backups \
    && chown -R www-data:www-data ${APP_HOME}

EXPOSE 9000

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
```

- [ ] **Step 6: Verify static file syntax**

Run:

```bash
test -f Dockerfile
test -f .dockerignore
test -f docker/php/php.ini
test -f docker/supervisor/supervisord.conf
test -f docker/cron/azpanel
```

Expected: exit code 0.

- [ ] **Step 7: Commit runtime files**

```bash
git add Dockerfile .dockerignore docker/php/php.ini docker/supervisor/supervisord.conf docker/cron/azpanel
git commit -m "feat: add docker app runtime"
```

## Task 2: Add Compose and Nginx Config

**Files:**
- Create: `docker-compose.yml`
- Create: `docker/nginx/http.conf`
- Create: `docker/nginx/https.conf`

- [ ] **Step 1: Add HTTP Nginx config template**

Create `docker/nginx/http.conf` with:

```nginx
server {
    listen 80;
    server_name _;
    root /var/www/html/public;
    index index.php index.html index.htm;

    location ^~ /.well-known/acme-challenge/ {
        root /var/www/certbot;
        default_type text/plain;
        allow all;
    }

    location / {
        try_files $uri $uri/ /index.php?s=$uri&$args;
    }

    location ~ \.php$ {
        fastcgi_pass app:9000;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $fastcgi_path_info;
    }

    location ~* \.(gif|jpg|jpeg|png|bmp|swf|ico)$ {
        expires 30d;
        access_log off;
    }

    location ~* \.(js|css)$ {
        expires 12h;
        access_log off;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}
```

- [ ] **Step 2: Add HTTPS Nginx config template**

Create `docker/nginx/https.conf` with:

```nginx
server {
    listen 80;
    server_name ${AZPANEL_DOMAIN};

    location ^~ /.well-known/acme-challenge/ {
        root /var/www/certbot;
        default_type text/plain;
        allow all;
    }

    location / {
        return 301 https://$host$request_uri;
    }
}

server {
    listen 443 ssl http2;
    server_name ${AZPANEL_DOMAIN};
    root /var/www/html/public;
    index index.php index.html index.htm;

    ssl_certificate /etc/nginx/certs/fullchain.pem;
    ssl_certificate_key /etc/nginx/certs/privkey.pem;
    ssl_session_timeout 10m;
    ssl_session_cache shared:SSL:10m;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers on;

    location ^~ /.well-known/acme-challenge/ {
        root /var/www/certbot;
        default_type text/plain;
        allow all;
    }

    location / {
        try_files $uri $uri/ /index.php?s=$uri&$args;
    }

    location ~ \.php$ {
        fastcgi_pass app:9000;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $fastcgi_path_info;
        fastcgi_param HTTPS on;
    }

    location ~* \.(gif|jpg|jpeg|png|bmp|swf|ico)$ {
        expires 30d;
        access_log off;
    }

    location ~* \.(js|css)$ {
        expires 12h;
        access_log off;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}
```

- [ ] **Step 3: Add Compose file**

Create `docker-compose.yml` with:

```yaml
services:
  app:
    build:
      context: .
      dockerfile: Dockerfile
    image: azpanel-app:latest
    container_name: azpanel-app
    restart: unless-stopped
    env_file:
      - .docker.env
    volumes:
      - ./:/var/www/html
      - ./docker/php/php.ini:/usr/local/etc/php/conf.d/azpanel.ini:ro
      - ./docker/supervisor/supervisord.conf:/etc/supervisor/conf.d/supervisord.conf:ro
    depends_on:
      db:
        condition: service_healthy

  web:
    image: nginx:1.27-alpine
    container_name: azpanel-web
    restart: unless-stopped
    env_file:
      - .docker.env
    ports:
      - "${HTTP_PORT:-80}:80"
      - "${HTTPS_PORT:-443}:443"
    volumes:
      - ./:/var/www/html:ro
      - ./docker/nginx/runtime/default.conf:/etc/nginx/conf.d/default.conf:ro
      - ./docker/certs:/etc/nginx/certs:ro
      - ./docker/certbot/www:/var/www/certbot
    depends_on:
      - app

  db:
    image: mariadb:10.11-jammy
    container_name: azpanel-db
    restart: unless-stopped
    env_file:
      - .docker.env
    environment:
      MARIADB_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
      MARIADB_DATABASE: ${DB_DATABASE}
      MARIADB_USER: ${DB_USERNAME}
      MARIADB_PASSWORD: ${DB_PASSWORD}
      TZ: Asia/Shanghai
    command:
      - --character-set-server=utf8mb4
      - --collation-server=utf8mb4_general_ci
      - --innodb-buffer-pool-size=128M
    volumes:
      - azpanel-db:/var/lib/mysql
    healthcheck:
      test: ["CMD-SHELL", "mariadb-admin ping -h 127.0.0.1 -uroot -p$${MARIADB_ROOT_PASSWORD} --silent"]
      interval: 10s
      timeout: 5s
      retries: 12

volumes:
  azpanel-db:
```

- [ ] **Step 4: Verify Compose template with temporary env**

Run:

```bash
cat > .docker.env <<'EOF'
AZPANEL_DOMAIN=localhost
HTTP_PORT=8080
HTTPS_PORT=8443
DB_ROOT_PASSWORD=rootpass
DB_DATABASE=azpanel
DB_USERNAME=azpanel
DB_PASSWORD=azpanelpass
EOF
mkdir -p docker/nginx/runtime docker/certs docker/certbot/www
cp docker/nginx/http.conf docker/nginx/runtime/default.conf
docker compose --env-file .docker.env config
rm -f .docker.env
rm -f docker/nginx/runtime/default.conf
```

Expected: `docker compose --env-file .docker.env config` exits 0 and prints normalized config.

- [ ] **Step 5: Commit Compose and Nginx files**

```bash
git add docker-compose.yml docker/nginx/http.conf docker/nginx/https.conf
git commit -m "feat: add docker compose and nginx config"
```

## Task 3: Add Interactive Deployment Script

**Files:**
- Create: `deploy.sh`

- [ ] **Step 1: Add Bash script**

Create executable `deploy.sh` with functions:

```bash
#!/usr/bin/env bash
set -euo pipefail

APP_NAME="azpanel"
COMPOSE="docker compose --env-file .docker.env"
ENV_FILE=".env"
DOCKER_ENV_FILE=".docker.env"
NGINX_RUNTIME_DIR="docker/nginx/runtime"
CERT_DIR="docker/certs"
CERTBOT_CONF_DIR="docker/certbot/conf"
CERTBOT_WEB_DIR="docker/certbot/www"
BACKUP_DIR="backups"

command_exists() {
    command -v "$1" >/dev/null 2>&1
}

require_docker() {
    command_exists docker || { echo "未找到 docker"; exit 1; }
    docker compose version >/dev/null 2>&1 || { echo "未找到 docker compose"; exit 1; }
}

random_secret() {
    if command_exists openssl; then
        openssl rand -base64 24 | tr -d '\n'
    else
        date +%s%N | sha256sum | awk '{print $1}'
    fi
}

prompt_default() {
    local prompt="$1"
    local default="$2"
    local value
    read -r -p "${prompt} [${default}]: " value
    echo "${value:-$default}"
}

prompt_secret_default() {
    local prompt="$1"
    local default="$2"
    local value
    read -r -s -p "${prompt} [留空自动生成]: " value
    echo
    echo "${value:-$default}"
}

prompt_yes_no() {
    local prompt="$1"
    local default="$2"
    local value
    read -r -p "${prompt} [${default}]: " value
    value="${value:-$default}"
    case "$value" in
        y|Y|yes|YES) return 0 ;;
        *) return 1 ;;
    esac
}

write_env_files() {
    local app_debug="$1"
    local db_name="$2"
    local db_user="$3"
    local db_password="$4"
    local db_root_password="$5"
    local http_port="$6"
    local https_port="$7"
    local domain="$8"

    cat > "$ENV_FILE" <<EOF
APP_DEBUG = ${app_debug}

[APP]
DEFAULT_TIMEZONE = Asia/Shanghai
APP_NAME = Azure

[DATABASE]
TYPE = mysql
HOSTNAME = db
DATABASE = ${db_name}
USERNAME = ${db_user}
PASSWORD = ${db_password}
HOSTPORT = 3306
CHARSET = utf8mb4
DEBUG = false

[THEME]
CARD_WIDTH = 10
CARD_RIGHT_OFFSET = 1

[LANG]
default_lang = zh-cn
EOF

    cat > "$DOCKER_ENV_FILE" <<EOF
AZPANEL_DOMAIN=${domain}
HTTP_PORT=${http_port}
HTTPS_PORT=${https_port}
DB_ROOT_PASSWORD=${db_root_password}
DB_DATABASE=${db_name}
DB_USERNAME=${db_user}
DB_PASSWORD=${db_password}
EOF
}

prepare_dirs() {
    mkdir -p "$NGINX_RUNTIME_DIR" "$CERT_DIR" "$CERTBOT_CONF_DIR" "$CERTBOT_WEB_DIR" "$BACKUP_DIR" runtime storage
    chown -R 33:33 runtime storage
    chmod -R u+rwX,g+rwX runtime storage
}

use_http_config() {
    cp docker/nginx/http.conf "$NGINX_RUNTIME_DIR/default.conf"
}

use_https_config() {
    local domain="$1"
    sed "s/\${AZPANEL_DOMAIN}/${domain}/g" docker/nginx/https.conf > "$NGINX_RUNTIME_DIR/default.conf"
}

copy_existing_cert() {
    local fullchain="$1"
    local privkey="$2"
    cp "$fullchain" "$CERT_DIR/fullchain.pem"
    cp "$privkey" "$CERT_DIR/privkey.pem"
}

wait_for_db() {
    echo "等待数据库启动..."
    for i in $(seq 1 60); do
        if $COMPOSE exec -T db mariadb-admin ping -h 127.0.0.1 -uroot -p"${DB_ROOT_PASSWORD}" --silent >/dev/null 2>&1; then
            echo "数据库已就绪"
            return 0
        fi
        sleep 2
    done
    echo "数据库启动超时"
    exit 1
}

load_docker_env() {
    if [ -f "$DOCKER_ENV_FILE" ]; then
        set -a
        . "./$DOCKER_ENV_FILE"
        set +a
    fi
}

import_sql() {
    load_docker_env
    wait_for_db
    echo "导入 database/azure.sql"
    $COMPOSE exec -T db mariadb -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" < database/azure.sql
    echo "导入 database/config.sql"
    $COMPOSE exec -T db mariadb -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" < database/config.sql
}

run_migrations() {
    $COMPOSE exec -T app php think migrate:run
    $COMPOSE exec -T app php think seed:run
}

install_dependencies() {
    $COMPOSE exec -T app composer install --no-dev --no-scripts --prefer-dist --optimize-autoloader
    $COMPOSE exec -T app php think service:discover
    $COMPOSE exec -T app php think vendor:publish
}

create_admin() {
    local email="$1"
    local password="$2"
    $COMPOSE exec -T app php think createAdmin --email "$email" --passwd "$password"
}

issue_letsencrypt() {
    local domain="$1"
    local email="$2"
    use_http_config
    $COMPOSE up -d web
    docker run --rm \
        -v "$(pwd)/$CERTBOT_CONF_DIR:/etc/letsencrypt" \
        -v "$(pwd)/$CERTBOT_WEB_DIR:/var/www/certbot" \
        certbot/certbot certonly --webroot \
        -w /var/www/certbot \
        -d "$domain" \
        --email "$email" \
        --agree-tos \
        --no-eff-email
    cp "$CERTBOT_CONF_DIR/live/$domain/fullchain.pem" "$CERT_DIR/fullchain.pem"
    cp "$CERTBOT_CONF_DIR/live/$domain/privkey.pem" "$CERT_DIR/privkey.pem"
    use_https_config "$domain"
    $COMPOSE up -d web
}

install_app() {
    require_docker
    prepare_dirs

    local domain
    domain="$(prompt_default "请输入域名，HTTP-only 可填 localhost" "localhost")"
    local http_port
    http_port="$(prompt_default "请输入 HTTP 端口" "80")"
    local https_port
    https_port="$(prompt_default "请输入 HTTPS 端口" "443")"
    local db_name
    db_name="$(prompt_default "请输入数据库名" "azpanel")"
    local db_user
    db_user="$(prompt_default "请输入数据库用户" "azpanel")"
    local db_password
    db_password="$(prompt_secret_default "请输入数据库密码" "$(random_secret)")"
    local db_root_password
    db_root_password="$(prompt_secret_default "请输入数据库 root 密码" "$(random_secret)")"

    write_env_files "false" "$db_name" "$db_user" "$db_password" "$db_root_password" "$http_port" "$https_port" "$domain"
    load_docker_env

    echo "HTTPS 模式：1) 不启用 2) 使用已有证书 3) Let's Encrypt"
    local https_mode
    https_mode="$(prompt_default "请选择 HTTPS 模式" "1")"
    case "$https_mode" in
        1)
            use_http_config
            ;;
        2)
            local fullchain privkey
            fullchain="$(prompt_default "请输入 fullchain.pem 路径" "")"
            privkey="$(prompt_default "请输入 privkey.pem 路径" "")"
            copy_existing_cert "$fullchain" "$privkey"
            use_https_config "$domain"
            ;;
        3)
            use_http_config
            ;;
        *)
            echo "HTTPS 模式无效"
            exit 1
            ;;
    esac

    $COMPOSE up -d --build db app web
    wait_for_db
    install_dependencies

    if [ "$https_mode" = "3" ]; then
        local email
        email="$(prompt_default "请输入 Let's Encrypt 邮箱" "admin@$domain")"
        issue_letsencrypt "$domain" "$email"
    fi

    if prompt_yes_no "是否导入基础 SQL" "y"; then
        import_sql
    fi
    if prompt_yes_no "是否执行 migrate 和 seed" "y"; then
        run_migrations
    fi
    if prompt_yes_no "是否创建管理员" "y"; then
        local admin_email admin_password
        admin_email="$(prompt_default "管理员邮箱" "admin@$domain")"
        admin_password="$(prompt_secret_default "管理员密码" "$(random_secret)")"
        create_admin "$admin_email" "$admin_password"
        echo "管理员邮箱: $admin_email"
        echo "管理员密码: $admin_password"
    fi

    echo "部署完成"
}

redeploy() {
    require_docker
    test -f "$DOCKER_ENV_FILE" || { echo "缺少 $DOCKER_ENV_FILE，请先 install"; exit 1; }
    $COMPOSE up -d --build --force-recreate app web
    install_dependencies
    echo "重新部署完成，数据库数据已保留"
}

uninstall() {
    require_docker
    $COMPOSE down
    echo "已卸载容器，数据库卷和配置文件已保留"
}

purge() {
    require_docker
    local confirm
    read -r -p "这会删除容器、数据库卷、证书和生成配置。输入 PURGE 确认: " confirm
    [ "$confirm" = "PURGE" ] || { echo "已取消"; exit 1; }
    $COMPOSE down -v --rmi local
    rm -f "$ENV_FILE" "$DOCKER_ENV_FILE"
    rm -rf "$NGINX_RUNTIME_DIR" "$CERT_DIR" "$CERTBOT_CONF_DIR" "$CERTBOT_WEB_DIR"
    echo "已完全卸载，源码未删除"
}

renew_cert() {
    require_docker
    docker run --rm \
        -v "$(pwd)/$CERTBOT_CONF_DIR:/etc/letsencrypt" \
        -v "$(pwd)/$CERTBOT_WEB_DIR:/var/www/certbot" \
        certbot/certbot renew --webroot -w /var/www/certbot
    load_docker_env
    if [ -n "${AZPANEL_DOMAIN:-}" ] && [ -f "$CERTBOT_CONF_DIR/live/$AZPANEL_DOMAIN/fullchain.pem" ]; then
        cp "$CERTBOT_CONF_DIR/live/$AZPANEL_DOMAIN/fullchain.pem" "$CERT_DIR/fullchain.pem"
        cp "$CERTBOT_CONF_DIR/live/$AZPANEL_DOMAIN/privkey.pem" "$CERT_DIR/privkey.pem"
    fi
    $COMPOSE exec -T web nginx -s reload
}

backup_db() {
    require_docker
    load_docker_env
    mkdir -p "$BACKUP_DIR"
    local file="$BACKUP_DIR/azpanel-$(date +%Y%m%d%H%M%S).sql"
    $COMPOSE exec -T db mariadb-dump -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" > "$file"
    echo "数据库已备份到 $file"
}

case "${1:-install}" in
    install) install_app ;;
    redeploy) redeploy ;;
    uninstall) uninstall ;;
    purge) purge ;;
    renew-cert) renew_cert ;;
    backup-db) backup_db ;;
    *)
        echo "用法: bash deploy.sh [install|redeploy|uninstall|purge|renew-cert|backup-db]"
        exit 1
        ;;
esac
```

- [ ] **Step 2: Make script executable**

Run:

```bash
chmod +x deploy.sh
```

- [ ] **Step 3: Verify shell syntax**

Run:

```bash
bash -n deploy.sh
```

Expected: exit code 0.

- [ ] **Step 4: Commit deploy script**

```bash
git add deploy.sh
git commit -m "feat: add interactive docker deploy script"
```

## Task 4: Add Chinese Docker Deployment Documentation

**Files:**
- Create: `docs/docker-deploy.md`

- [ ] **Step 1: Add deployment guide**

Create `docs/docker-deploy.md` with these sections:

```markdown
# Docker 部署

本文说明如何使用 Docker Compose 部署 azpanel。

## 结构

部署后包含 3 个容器：

- `azpanel-web`：Nginx，处理 HTTP/HTTPS 和静态资源。
- `azpanel-app`：PHP-FPM、Supervisor 和 cron。
- `azpanel-db`：`mariadb:10.11-jammy`。

cron 在 `azpanel-app` 容器内运行，定时任务默认启用。

## 前置要求

- Docker
- Docker Compose v2
- 如果使用 Let's Encrypt，域名必须解析到当前服务器，且 80 端口可从公网访问。

## 首次部署

```bash
bash deploy.sh install
```

脚本会交互生成 `.env` 和 `.docker.env`，启动容器，导入数据库，执行迁移和 seed，并按需创建管理员。

## HTTPS

部署脚本支持：

1. 不启用 HTTPS。
2. 使用已有证书。
3. 使用 Let's Encrypt 自动申请证书。

使用已有证书时，脚本会把证书复制到 `docker/certs/fullchain.pem` 和 `docker/certs/privkey.pem`。

## 重新部署

保留数据库和配置，只重建应用和 Nginx：

```bash
bash deploy.sh redeploy
```

## 备份数据库

```bash
bash deploy.sh backup-db
```

备份文件会保存到 `backups/`。

## 卸载

普通卸载会删除容器和网络，但保留数据库卷、证书和配置：

```bash
bash deploy.sh uninstall
```

完全卸载会删除数据库卷、证书和生成配置，需要输入 `PURGE` 确认：

```bash
bash deploy.sh purge
```

## 续签证书

```bash
bash deploy.sh renew-cert
```

可以在宿主机 crontab 中添加：

```cron
0 3 * * * cd /path/to/azpanel && bash deploy.sh renew-cert
```

## 手动命令

执行迁移和 seed：

```bash
docker compose --env-file .docker.env exec app php think migrate:run
docker compose --env-file .docker.env exec app php think seed:run
```

创建管理员：

```bash
docker compose --env-file .docker.env exec app php think createAdmin --email admin@example.com --passwd your-password
```

查看日志：

```bash
docker compose --env-file .docker.env logs -f app
docker compose --env-file .docker.env logs -f web
docker compose --env-file .docker.env logs -f db
```

## 排错

- 访问 404：确认 Nginx root 指向 `public/`，并且 runtime config 存在于 `docker/nginx/runtime/default.conf`。
- 数据库连接失败：检查 `.env` 中 `HOSTNAME=db`，并确认 `azpanel-db` 健康。
- Let's Encrypt 失败：确认域名解析正确，80 端口没有被宿主机其他服务占用。
- cron 未执行：查看 `docker compose --env-file .docker.env logs app`，确认 `supervisord` 同时启动了 `php-fpm` 和 `cron`。
```

- [ ] **Step 2: Verify doc exists**

Run:

```bash
test -f docs/docker-deploy.md
```

Expected: exit code 0.

- [ ] **Step 3: Commit documentation**

```bash
git add docs/docker-deploy.md
git commit -m "docs: add docker deployment guide"
```

## Task 5: Final Verification

**Files:**
- Verify all Docker deployment files.

- [ ] **Step 1: Verify shell syntax**

Run:

```bash
bash -n deploy.sh
```

Expected: exit code 0.

- [ ] **Step 2: Verify Compose config**

Run:

```bash
cat > .docker.env <<'EOF'
AZPANEL_DOMAIN=localhost
HTTP_PORT=8080
HTTPS_PORT=8443
DB_ROOT_PASSWORD=rootpass
DB_DATABASE=azpanel
DB_USERNAME=azpanel
DB_PASSWORD=azpanelpass
EOF
mkdir -p docker/nginx/runtime docker/certs docker/certbot/www
cp docker/nginx/http.conf docker/nginx/runtime/default.conf
docker compose --env-file .docker.env config
rm -f .docker.env
rm -f docker/nginx/runtime/default.conf
```

Expected: `docker compose --env-file .docker.env config` exits 0.

- [ ] **Step 3: Build app image**

Run:

```bash
docker build -t azpanel-test .
```

Expected: image builds successfully. If Docker daemon is unavailable, record that image build could not be verified.

- [ ] **Step 4: Verify PHP syntax remains clean**

Run:

```bash
find app route -name '*.php' -print0 | xargs -0 -n1 php -l
```

Expected: all files report `No syntax errors detected`.

- [ ] **Step 5: Verify git status**

Run:

```bash
git status --short
```

Expected: no uncommitted files after final commits.
