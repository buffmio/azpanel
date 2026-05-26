## azpanel
演示站点：[https://azpanel.net](https://azpanel.net)

允许注册并正常使用

## 功能
创建 azure 和 aws 虚拟机

## telegram
频道：[https://t.me/azpanel](https://t.me/azpanel)

群聊：[https://t.me/+w_DuAFGop4kzOGYx](https://t.me/+w_DuAFGop4kzOGYx)
## 安装教程
- lnmp.org

[https://github.com/azpanel/azpanel/wiki/lnmp.org](https://github.com/azpanel/azpanel/wiki/lnmp.org)

- bt.cn

[https://github.com/azpanel/azpanel/wiki/bt.cn](https://github.com/azpanel/azpanel/wiki/bt.cn)

## 单容器部署

本项目提供 Panel 应用容器。容器只包含 PHP-FPM、PHP CLI、Composer 依赖、项目代码和 supercronic 固定定时任务，不包含 nginx 和 MySQL/MariaDB。

用户需要自行准备：

1. nginx。
2. MySQL 或 MariaDB。
3. 已创建的数据库。

容器启动时会自动等待数据库连接。如果数据库为空，会自动导入 `database/azure.sql` 和 `database/config.sql`，随后固定执行 `php /app/think migrate:run` 和 `php /app/think seed:run`。

### 构建镜像

```bash
docker build -t azpanel:local .
```

### 准备配置

```bash
cp .env.docker.example .env
```

编辑 `.env`，填写外部 MySQL/MariaDB 地址、数据库名、用户名和密码。

### 启动容器

如果 nginx 也在 Docker 网络中，可以让 nginx 通过 `azpanel:9000` 访问 PHP-FPM：

```bash
docker run -d --name azpanel \
  --restart unless-stopped \
  -v $(pwd)/.env:/app/.env \
  -v $(pwd)/runtime:/app/runtime \
  azpanel:local
```

如果 nginx 在宿主机上，可以按需发布 FastCGI 端口：

```bash
docker run -d --name azpanel \
  --restart unless-stopped \
  -p 9000:9000 \
  -v $(pwd)/.env:/app/.env \
  -v $(pwd)/runtime:/app/runtime \
  azpanel:local
```

容器启动后会先完成数据库初始化，再运行 `php-fpm` 和 supercronic。固定定时任务位于 `docker/supercronic.cron`。

### nginx

nginx 由用户自行配置。可参考 `docker/nginx-example.conf`，把 PHP 请求转发到 Panel 容器的 `9000` 端口。

示例中的 `fastcgi_param SCRIPT_FILENAME /app/public$fastcgi_script_name;` 对应容器内路径。外部 nginx 的 `root` 应指向你部署在宿主机上的项目 `public` 目录。
