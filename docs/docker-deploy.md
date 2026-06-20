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
