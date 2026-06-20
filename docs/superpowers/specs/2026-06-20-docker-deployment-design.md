# Docker Deployment Design

## Goal

Add a production-oriented Docker deployment path for azpanel that can be installed interactively, supports HTTP or HTTPS, uses `mariadb:10.11-jammy`, and provides safe redeploy and uninstall operations.

## References

The Docker flow mirrors the existing LNMP deployment guide:

- PHP runtime must allow `system`, `proc_open`, and `proc_get_status`.
- Nginx document root must point to `public/`.
- Nginx must use ThinkPHP rewrite rules.
- Database initialization imports `database/azure.sql` first, then `database/config.sql`.
- Additional database updates run through `php think migrate:run` and `php think seed:run`.
- Admin creation uses `php think createAdmin --email ... --passwd ...`.
- Scheduled jobs map to the existing ThinkPHP console commands.

Reference URL: https://github.com/azpanel/azpanel/wiki/lnmp.org

## Architecture

Docker Compose will run separate services:

- `web`: Nginx serving static files from `public/` and forwarding PHP requests to `app:9000`.
- `app`: PHP 8.3 FPM with Composer dependencies, required PHP extensions, and cron managed inside the same container.
- `db`: MariaDB using `mariadb:10.11-jammy`.

Application files are bind-mounted into containers for straightforward upgrade and debugging. Persistent data lives in Docker volumes for MariaDB and in project directories for runtime, logs, and certificates.

## Database

The database container uses:

```yaml
image: mariadb:10.11-jammy
```

The generated `.env` keeps ThinkPHP's MySQL driver:

```ini
[DATABASE]
TYPE = mysql
HOSTNAME = db
DATABASE = azpanel
USERNAME = azpanel
PASSWORD = generated-or-user-provided-password
HOSTPORT = 3306
CHARSET = utf8mb4
DEBUG = false
```

MariaDB is initialized explicitly by the deployment script, not by automatic startup hooks. This avoids re-importing destructive SQL on container restart.

## HTTPS

The deployment script supports three modes:

1. HTTP only.
2. HTTPS with existing certificate files.
3. HTTPS with Let's Encrypt certificate issuance through a temporary `certbot/certbot` container.

For HTTPS, Nginx listens on port 443 and redirects port 80 traffic to HTTPS. The `/.well-known/acme-challenge/` path remains available for certificate issuance and renewal.

Certificate files are stored under Docker deployment directories and mounted read-only into Nginx. `deploy.sh renew-cert` renews Let's Encrypt certificates and reloads Nginx.

## Interactive Script

Create `deploy.sh` with these commands:

```bash
bash deploy.sh install
bash deploy.sh redeploy
bash deploy.sh uninstall
bash deploy.sh purge
bash deploy.sh renew-cert
bash deploy.sh backup-db
```

`install` is also the default when no command is provided.

### install

The script prompts for:

- Public domain name, optional for HTTP-only IP deployments.
- HTTP port, default `80`.
- HTTPS mode.
- HTTPS port, default `443`.
- Existing certificate paths or Let's Encrypt email when HTTPS is enabled.
- Database name, user, and password, with generated secure defaults.
- Admin email and password.
- Whether to import base SQL.
- Whether to run migrations and seeds.
- Whether to create the admin user.

The script then:

1. Generates `.env` and `.docker.env`.
2. Generates or selects Nginx config.
3. Creates required runtime and certificate directories.
4. Starts `db`, waits until it accepts connections.
5. Starts `app` and `web`.
6. Imports `database/azure.sql` and `database/config.sql` when requested.
7. Runs `php think migrate:run` and `php think seed:run` when requested.
8. Creates an admin account when requested.
9. Starts cron inside the `app` container together with PHP-FPM.

### redeploy

`redeploy` preserves `.env`, `.docker.env`, certificates, and the MariaDB data volume. It rebuilds the app image and recreates app/web containers.

It must not import `database/azure.sql` or `database/config.sql` unless the user explicitly chooses to reinitialize the database.

### uninstall

`uninstall` stops and removes containers and the Compose network. It preserves:

- `.env`
- `.docker.env`
- database volume
- certificates
- runtime data

### purge

`purge` is a destructive full uninstall. It requires typing `PURGE` before it proceeds. It removes:

- containers
- Compose network
- MariaDB data volume
- generated deployment env files
- generated certificates
- generated Nginx runtime config

It must not remove source code or git history.

### backup-db

`backup-db` writes a timestamped SQL dump to `backups/`.

## Nginx

Nginx must:

- Use `public/` as root.
- Apply ThinkPHP rewrite behavior through `try_files $uri $uri/ /index.php?s=$uri&$args;`.
- Deny hidden files except `/.well-known`.
- Cache static image, CSS, and JS assets.
- Forward PHP requests to `app:9000`.
- Support HTTP-only and HTTPS configurations.

## PHP

The app image must install PHP extensions required by the project:

- `pdo_mysql`
- `mysqli`
- `mbstring`
- `curl`
- `zip`
- `gd`
- `bcmath`
- `pcntl`
- `sockets`

The app image also installs `supervisor` and `cron`. `supervisord` is the app container entrypoint and manages:

- `php-fpm`, always enabled.
- `cron`, always enabled.

The image must run `composer install --no-dev --optimize-autoloader` for production use. The `post-autoload-dump` scripts are allowed during image build if the ThinkPHP runtime can discover services successfully; if they fail due to build-time environment constraints, the Dockerfile should use `composer install --no-dev --no-scripts --optimize-autoloader` and run `php think service:discover` inside the initialized container when needed.

PHP config must not disable `system`, `proc_open`, or `proc_get_status`.

## Cron

Cron always runs inside the `app` container. It uses the LNMP guide's scheduled commands:

```cron
0 0 * * * php /var/www/html/think tools --action statisticsTraffic
0 * * * * php /var/www/html/think autoRefreshAccount
0 * * * * php /var/www/html/think closeTimeoutTask
0 * * * * php /var/www/html/think trafficControlStop
*/5 * * * * php /var/www/html/think trafficControlStart
```

Cron logs go to container stdout/stderr or `/var/log/cron.log`, and `docker compose logs app` can inspect them.

## Documentation

Add `docs/docker-deploy.md` in Chinese. It must cover:

- Prerequisites: Docker and Docker Compose.
- First install.
- HTTPS choices.
- Redeploy.
- Backup.
- Uninstall vs purge.
- Common troubleshooting.
- Manual commands for migration, seed, admin creation, and certificate renewal.

## Verification

Implementation must pass:

```bash
docker compose config
bash -n deploy.sh
docker build -t azpanel-test .
```

If Docker daemon access is unavailable, at minimum run:

```bash
bash -n deploy.sh
docker compose config
```

and report that image build could not be verified.
