#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT_DIR"

APP_ENV_FILE="${APP_ENV_FILE:-.env}"
DEPLOY_ENV_FILE="${DEPLOY_ENV_FILE:-.deploy.env}"
NGINX_CONF_FILE="${NGINX_CONF_FILE:-docker/nginx-generated.conf}"
HTTP_PORT="${HTTP_PORT:-80}"
HTTPS_PORT="${HTTPS_PORT:-443}"
DB_DATABASE="${DB_DATABASE:-azpanel}"
DB_USERNAME="${DB_USERNAME:-azpanel}"
SKIP_PULL="${SKIP_PULL:-0}"
CLI_HTTP_PORT=""
CLI_HTTPS_PORT=""
FORCE_ENV="${FORCE_ENV:-0}"
FORCE_ENV_DEFAULT="0"
ENABLE_HTTPS="${ENABLE_HTTPS:-}"
DOMAIN="${DOMAIN:-}"
CERTBOT_EMAIL="${CERTBOT_EMAIL:-}"
ASSUME_YES="${ASSUME_YES:-0}"

if [ -f "$DEPLOY_ENV_FILE" ]; then
    # shellcheck disable=SC1090
    source "$DEPLOY_ENV_FILE"
fi

usage() {
    cat <<'USAGE'
Usage: ./deploy.sh [options]

Options:
  --port PORT        Host HTTP port exposed by nginx. Default: 80
  --https-port PORT  Host HTTPS port exposed by nginx. Default: 443
  --https           Enable HTTPS and request a Let's Encrypt certificate
  --no-https        Disable HTTPS
  --domain DOMAIN   Domain name for nginx and Let's Encrypt
  --email EMAIL     Email address for Let's Encrypt notices
  --db-name NAME    MySQL database name. Default: azpanel
  --db-user USER    MySQL user. Default: azpanel
  --db-password PASS MySQL user password. Generated when omitted
  --root-password PASS MySQL root password. Generated when omitted
  --force-env        Rewrite .env to use the bundled MySQL service
  --skip-pull        Do not run git pull before deployment
  -y, --yes          Use defaults for unanswered interactive prompts
  --help             Show this help

Environment:
  HTTP_PORT          Same as --port
  HTTPS_PORT         Same as --https-port
  ENABLE_HTTPS=1    Same as --https
  DOMAIN            Same as --domain
  CERTBOT_EMAIL     Same as --email
  DB_DATABASE        MySQL database name. Default: azpanel
  DB_USERNAME        MySQL user. Default: azpanel
  DB_PASSWORD        MySQL password. Generated when omitted
  MYSQL_ROOT_PASSWORD MySQL root password. Generated when omitted
  FORCE_ENV=1       Same as --force-env
  SKIP_PULL=1        Same as --skip-pull
  ASSUME_YES=1      Same as --yes

The script starts a full Docker stack: MySQL, azpanel PHP-FPM, nginx, and
optional Let's Encrypt SSL via certbot. Existing .env is preserved unless
--force-env is used.
USAGE
}

while [ "$#" -gt 0 ]; do
    case "$1" in
        --port)
            if [ "$#" -lt 2 ]; then
                echo "--port requires a value" >&2
                exit 1
            fi
            CLI_HTTP_PORT="$2"
            shift 2
            ;;
        --https-port)
            if [ "$#" -lt 2 ]; then
                echo "--https-port requires a value" >&2
                exit 1
            fi
            CLI_HTTPS_PORT="$2"
            shift 2
            ;;
        --https)
            ENABLE_HTTPS=1
            shift
            ;;
        --no-https)
            ENABLE_HTTPS=0
            shift
            ;;
        --domain)
            if [ "$#" -lt 2 ]; then
                echo "--domain requires a value" >&2
                exit 1
            fi
            DOMAIN="$2"
            shift 2
            ;;
        --email)
            if [ "$#" -lt 2 ]; then
                echo "--email requires a value" >&2
                exit 1
            fi
            CERTBOT_EMAIL="$2"
            shift 2
            ;;
        --db-name)
            if [ "$#" -lt 2 ]; then
                echo "--db-name requires a value" >&2
                exit 1
            fi
            DB_DATABASE="$2"
            shift 2
            ;;
        --db-user)
            if [ "$#" -lt 2 ]; then
                echo "--db-user requires a value" >&2
                exit 1
            fi
            DB_USERNAME="$2"
            shift 2
            ;;
        --db-password)
            if [ "$#" -lt 2 ]; then
                echo "--db-password requires a value" >&2
                exit 1
            fi
            DB_PASSWORD="$2"
            shift 2
            ;;
        --root-password)
            if [ "$#" -lt 2 ]; then
                echo "--root-password requires a value" >&2
                exit 1
            fi
            MYSQL_ROOT_PASSWORD="$2"
            shift 2
            ;;
        --force-env)
            FORCE_ENV=1
            shift
            ;;
        --skip-pull)
            SKIP_PULL=1
            shift
            ;;
        -y|--yes)
            ASSUME_YES=1
            shift
            ;;
        --help)
            usage
            exit 0
            ;;
        *)
            echo "Unknown option: $1" >&2
            usage
            exit 1
            ;;
    esac
done

require_command() {
    if ! command -v "$1" >/dev/null 2>&1; then
        echo "Missing command: $1" >&2
        exit 1
    fi
}

compose_command() {
    if docker compose version >/dev/null 2>&1; then
        COMPOSE=(docker compose)
        return
    fi

    if command -v docker-compose >/dev/null 2>&1; then
        COMPOSE=(docker-compose)
        return
    fi

    echo "Missing Docker Compose. Install Docker Compose v2 or docker-compose." >&2
    exit 1
}

random_secret() {
    if command -v openssl >/dev/null 2>&1; then
        openssl rand -base64 32 | tr -d '\n'
        return
    fi

    date +%s%N | sha256sum | awk '{print $1}'
}

is_interactive() {
    [ -t 0 ] && [ "$ASSUME_YES" != "1" ]
}

prompt_value() {
    local prompt="$1"
    local default_value="$2"
    local value=""

    if ! is_interactive; then
        printf '%s' "$default_value"
        return
    fi

    read -r -p "$prompt [$default_value]: " value
    printf '%s' "${value:-$default_value}"
}

prompt_secret() {
    local prompt="$1"
    local default_value="$2"
    local value=""

    if ! is_interactive; then
        printf '%s' "$default_value"
        return
    fi

    read -r -s -p "$prompt [留空自动生成/保留]: " value
    echo
    printf '%s' "${value:-$default_value}"
}

prompt_yes_no() {
    local prompt="$1"
    local default_value="$2"
    local suffix="[y/N]"
    local value=""

    if [ "$default_value" = "1" ]; then
        suffix="[Y/n]"
    fi

    if ! is_interactive; then
        printf '%s' "$default_value"
        return
    fi

    read -r -p "$prompt $suffix: " value
    case "$value" in
        y|Y|yes|YES) printf '1' ;;
        n|N|no|NO) printf '0' ;;
        *) printf '%s' "$default_value" ;;
    esac
}

ini_value() {
    local section="$1"
    local key="$2"
    local file="$3"

    awk -F '=' -v section="$section" -v key="$key" '
        $0 ~ "^\\[" section "\\]" { in_section = 1; next }
        $0 ~ "^\\[" { in_section = 0 }
        in_section == 1 {
            name = $1
            value = $2
            gsub(/^[ \t]+|[ \t]+$/, "", name)
            gsub(/^[ \t]+|[ \t]+$/, "", value)
            if (name == key) {
                print value
                exit
            }
        }
    ' "$file"
}

write_app_env() {
    cat > "$APP_ENV_FILE" <<EOF
APP_DEBUG = false

[APP]
DEFAULT_TIMEZONE = Asia/Shanghai
APP_NAME = Azure

[DATABASE]
TYPE = mysql
HOSTNAME = mysql
DATABASE = ${DB_DATABASE}
USERNAME = ${DB_USERNAME}
PASSWORD = ${DB_PASSWORD}
HOSTPORT = 3306
CHARSET = utf8mb4
DEBUG = false

[THEME]
CARD_WIDTH = 10
CARD_RIGHT_OFFSET = 1

[LANG]
default_lang = zh-cn
EOF
}

write_deploy_env() {
    cat > "$DEPLOY_ENV_FILE" <<EOF
HTTP_PORT=${HTTP_PORT}
HTTPS_PORT=${HTTPS_PORT}
APP_IMAGE=azpanel:local
ENABLE_HTTPS=${ENABLE_HTTPS}
DOMAIN=${DOMAIN}
CERTBOT_EMAIL=${CERTBOT_EMAIL}
DB_DATABASE=${DB_DATABASE}
DB_USERNAME=${DB_USERNAME}
DB_PASSWORD=${DB_PASSWORD}
MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_PASSWORD}
EOF
}

write_http_nginx_conf() {
    mkdir -p "$(dirname "$NGINX_CONF_FILE")"
    cat > "$NGINX_CONF_FILE" <<EOF
server {
    listen 80;
    server_name ${DOMAIN:-_};
    root /app/public;
    index index.php index.html;

    location ^~ /.well-known/acme-challenge/ {
        root /var/www/certbot;
        default_type "text/plain";
        try_files \$uri =404;
    }

    location / {
        if (!-e \$request_filename) {
            rewrite ^(.*)\$ /index.php?s=\$1 last;
            break;
        }
    }

    location ~ \.php\$ {
        fastcgi_pass azpanel:9000;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /app/public\$fastcgi_script_name;
    }
}
EOF
}

write_https_nginx_conf() {
    mkdir -p "$(dirname "$NGINX_CONF_FILE")"
    cat > "$NGINX_CONF_FILE" <<EOF
server {
    listen 80;
    server_name ${DOMAIN};

    location ^~ /.well-known/acme-challenge/ {
        root /var/www/certbot;
        default_type "text/plain";
        try_files \$uri =404;
    }

    location / {
        return 301 https://\$host\$request_uri;
    }
}

server {
    listen 443 ssl http2;
    server_name ${DOMAIN};
    root /app/public;
    index index.php index.html;

    ssl_certificate /etc/letsencrypt/live/${DOMAIN}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/${DOMAIN}/privkey.pem;
    ssl_session_timeout 1d;
    ssl_session_cache shared:SSL:10m;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers off;

    location / {
        if (!-e \$request_filename) {
            rewrite ^(.*)\$ /index.php?s=\$1 last;
            break;
        }
    }

    location ~ \.php\$ {
        fastcgi_pass azpanel:9000;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /app/public\$fastcgi_script_name;
    }
}
EOF
}

run_git_pull() {
    if [ ! -d .git ] || [ "$SKIP_PULL" = "1" ]; then
        return
    fi

    if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
        echo "Working tree has local changes. Commit/stash them, or rerun with --skip-pull." >&2
        exit 1
    fi

    git pull --ff-only
}

require_command docker
compose_command

if [ -n "$CLI_HTTP_PORT" ]; then
    HTTP_PORT="$CLI_HTTP_PORT"
fi

if [ -n "$CLI_HTTPS_PORT" ]; then
    HTTPS_PORT="$CLI_HTTPS_PORT"
fi

if [ -f "$APP_ENV_FILE" ] && [ "$FORCE_ENV" != "1" ]; then
    DB_HOSTNAME="$(ini_value DATABASE HOSTNAME "$APP_ENV_FILE" || true)"
    DB_DATABASE="$(ini_value DATABASE DATABASE "$APP_ENV_FILE" || true)"
    DB_USERNAME="$(ini_value DATABASE USERNAME "$APP_ENV_FILE" || true)"
    DB_PASSWORD="$(ini_value DATABASE PASSWORD "$APP_ENV_FILE" || true)"
    DB_DATABASE="${DB_DATABASE:-azpanel}"
    DB_USERNAME="${DB_USERNAME:-azpanel}"
    if [ -n "$DB_HOSTNAME" ] && [ "$DB_HOSTNAME" != "mysql" ]; then
        echo "Existing $APP_ENV_FILE uses database host '$DB_HOSTNAME'."
        echo "The bundled MySQL service will start, but AzPanel will use that configured host."
        echo "Use --force-env to rewrite $APP_ENV_FILE for the bundled MySQL service."
        FORCE_ENV_DEFAULT="1"
    fi
fi

DB_PASSWORD="${DB_PASSWORD:-$(random_secret)}"
MYSQL_ROOT_PASSWORD="${MYSQL_ROOT_PASSWORD:-$(random_secret)}"

echo "AzPanel full Docker deployment"
echo

RUN_GIT_PULL="$(prompt_yes_no "Run git pull --ff-only before deployment?" "$([ "$SKIP_PULL" = "1" ] && echo 0 || echo 1)")"
SKIP_PULL="$([ "$RUN_GIT_PULL" = "1" ] && echo 0 || echo 1)"

HTTP_PORT="$(prompt_value "HTTP port" "$HTTP_PORT")"
ENABLE_HTTPS="${ENABLE_HTTPS:-0}"
ENABLE_HTTPS="$(prompt_yes_no "Enable HTTPS with Let's Encrypt?" "$ENABLE_HTTPS")"

if [ "$ENABLE_HTTPS" = "1" ]; then
    DOMAIN="$(prompt_value "Domain name" "$DOMAIN")"
    CERTBOT_EMAIL="$(prompt_value "Let's Encrypt email" "$CERTBOT_EMAIL")"
    HTTPS_PORT="$(prompt_value "HTTPS port" "$HTTPS_PORT")"

    if [ -z "$DOMAIN" ] || [ -z "$CERTBOT_EMAIL" ]; then
        echo "HTTPS requires both domain and email." >&2
        exit 1
    fi

    if [ "$HTTP_PORT" != "80" ]; then
        echo "Warning: Let's Encrypt HTTP-01 validation normally requires public port 80."
        echo "Current host HTTP port is ${HTTP_PORT}. Make sure external port 80 reaches this nginx service."
    fi
fi

DB_DATABASE="$(prompt_value "Database name" "$DB_DATABASE")"
DB_USERNAME="$(prompt_value "Database user" "$DB_USERNAME")"
DB_PASSWORD="$(prompt_secret "Database password" "$DB_PASSWORD")"
MYSQL_ROOT_PASSWORD="$(prompt_secret "MySQL root password" "$MYSQL_ROOT_PASSWORD")"

if [ -f "$APP_ENV_FILE" ] && [ "$FORCE_ENV" != "1" ]; then
    FORCE_ENV="$(prompt_yes_no "Rewrite $APP_ENV_FILE for bundled MySQL?" "$FORCE_ENV_DEFAULT")"
fi

run_git_pull

if [ ! -f "$APP_ENV_FILE" ] || [ "$FORCE_ENV" = "1" ]; then
    write_app_env
    echo "Wrote $APP_ENV_FILE for Docker deployment."
fi

write_deploy_env
write_http_nginx_conf
mkdir -p runtime

"${COMPOSE[@]}" --env-file "$DEPLOY_ENV_FILE" up -d --build

if [ "$ENABLE_HTTPS" = "1" ]; then
    echo "Requesting Let's Encrypt certificate for ${DOMAIN}..."
    "${COMPOSE[@]}" --env-file "$DEPLOY_ENV_FILE" run --rm certbot certonly \
        --webroot \
        --webroot-path /var/www/certbot \
        --domain "$DOMAIN" \
        --email "$CERTBOT_EMAIL" \
        --agree-tos \
        --no-eff-email

    write_https_nginx_conf
    "${COMPOSE[@]}" --env-file "$DEPLOY_ENV_FILE" up -d nginx
    "${COMPOSE[@]}" --env-file "$DEPLOY_ENV_FILE" exec -T nginx nginx -s reload
fi

echo
echo "AzPanel stack is starting."
if [ "$ENABLE_HTTPS" = "1" ]; then
    echo "URL: https://${DOMAIN}"
else
    echo "URL: http://127.0.0.1:${HTTP_PORT}"
fi
echo
echo "Useful commands:"
echo "  ${COMPOSE[*]} --env-file ${DEPLOY_ENV_FILE} ps"
echo "  ${COMPOSE[*]} --env-file ${DEPLOY_ENV_FILE} logs -f"
echo "  ${COMPOSE[*]} --env-file ${DEPLOY_ENV_FILE} down"
