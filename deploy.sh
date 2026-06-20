#!/usr/bin/env bash
set -euo pipefail

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

require_docker_env() {
    test -f "$DOCKER_ENV_FILE" || { echo "缺少 $DOCKER_ENV_FILE，请先执行 install"; exit 1; }
}

random_secret() {
    if command_exists openssl; then
        openssl rand -hex 24 | tr -d '\n'
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
    printf '\n' >&2
    printf '%s\n' "${value:-$default}"
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

validate_env_value() {
    local name="$1"
    local value="$2"
    if printf '%s' "$value" | grep -Eq '[[:space:]]'; then
        echo "$name 不能包含空白字符"
        exit 1
    fi
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

    validate_env_value "数据库名" "$db_name"
    validate_env_value "数据库用户" "$db_user"
    validate_env_value "数据库密码" "$db_password"
    validate_env_value "数据库 root 密码" "$db_root_password"

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
    chmod -R 755 runtime storage
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
    test -f "$fullchain" || { echo "证书文件不存在: $fullchain"; exit 1; }
    test -f "$privkey" || { echo "私钥文件不存在: $privkey"; exit 1; }
    cp "$fullchain" "$CERT_DIR/fullchain.pem"
    cp "$privkey" "$CERT_DIR/privkey.pem"
}

load_docker_env() {
    require_docker_env
    set -a
    . "./$DOCKER_ENV_FILE"
    set +a
}

wait_for_db() {
    load_docker_env
    echo "等待数据库启动..."
    for _ in $(seq 1 60); do
        if $COMPOSE exec -T db mariadb-admin ping -h 127.0.0.1 -uroot -p"${DB_ROOT_PASSWORD}" --silent >/dev/null 2>&1; then
            echo "数据库已就绪"
            return 0
        fi
        sleep 2
    done
    echo "数据库启动超时"
    exit 1
}

install_dependencies() {
    $COMPOSE exec -T app composer install --no-dev --no-scripts --prefer-dist --optimize-autoloader
    $COMPOSE exec -T app php think service:discover
    $COMPOSE exec -T app php think vendor:publish
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
    require_docker_env
    $COMPOSE up -d --build --force-recreate app web
    install_dependencies
    echo "重新部署完成，数据库数据已保留"
}

uninstall() {
    require_docker
    require_docker_env
    $COMPOSE down
    echo "已卸载容器，数据库卷和配置文件已保留"
}

purge() {
    require_docker
    require_docker_env
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
    require_docker_env
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
