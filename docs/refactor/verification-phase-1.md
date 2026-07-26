# 第一阶段验证

验证日期：2026-07-27。

验证状态使用 `通过`、`未通过`、`未运行` 或 `阻塞`。`通过` 必须有本次验证的实际命令或观察结果；依赖真实邮件、hCaptcha、Azure、Telegram 等外部服务的项目不得以替代检查冒充端到端通过。

## 验证清单

- `composer install`：从锁文件安装成功。
- `composer test`：PHPUnit 全部通过。
- `composer analyse`：不新增 PHPStan 错误。
- `npm run test:js`：请求层测试全部通过。
- `npm run check:js`：新 JS 文件语法全部有效。
- 宿主 `php think run`：未运行；当前宿主 PHP 缺少 `pdo_mysql`，运行态改由无 bind mount 的一次性应用镜像验证。
- `/login`：邮箱、密码、两类验证码条件分支及失败反馈可用。
- `/register`：关闭注册、邮箱验证码、两类图形验证码、后端成功及 JS navigation 回调分别验证。
- `/forget`：验证码获取、密码不一致、错误验证码、后端成功及 JS navigation 回调分别验证。
- 视口 `390×844`、`768×1024`、`1440×900`：无不可达控件和意外横向滚动。

## 自动化验证

| 检查 | 命令 | 预期结果 | 状态 | 实际结果 / 证据 |
| --- | --- | --- | --- | --- |
| PHP 依赖 | `composer install` | 从 `composer.lock` 安装成功 | 通过 | 退出码 0；无需安装、更新或删除包，autoload、`service:discover` 和 `vendor:publish` 成功。 |
| PHP 测试 | `composer test` | PHPUnit 全部通过 | 通过 | 退出码 0；`OK (12 tests, 58 assertions)`。 |
| PHP 静态分析 | `composer analyse` | 不新增 PHPStan 错误 | 未通过 | 退出码 1；PHPStan 输出 `No rules detected`。当前 `phpstan.neon` 只有 `ignoreErrors`，没有规则级别或自定义 rules，因此本次不能证明“无新增错误”。 |
| JS 请求层测试 | `npm run test:js` | 请求层测试全部通过 | 通过 | 退出码 0；21 个测试通过，0 个失败。 |
| JS 语法 | `npm run check:js` | 新 JS 文件语法全部有效 | 通过 | 退出码 0；目标 JS 文件均通过 `node --check`。 |
| 部署脚本语法 | `bash -n deploy.sh` | shell 语法检查通过 | 通过 | 退出码 0，无输出。 |
| Compose 配置 | `docker compose config` | Compose 配置可解析 | 通过 | 首次因缺少本地 `.docker.env` 和数据库变量退出 1；创建仅用于验证的占位 `.env`、`.docker.env` 后，原命令退出 0 并输出完整配置。验证文件随后删除。未启动整套 Compose。 |

## 可复现实验命令

以下所有 bash fenced blocks 必须从仓库根目录开始、按顺序粘贴到同一个 shell session；不要单独执行中间代码块。第一段建立隔离目录和只管理带本次标签的 Docker 资源及严格限定临时目录的 cleanup trap，后续命令只在 `git archive HEAD` 导出的临时源码中创建环境文件和构建上下文。变量和凭据仅用于一次性本地验证。

Docker daemon 无法 bind mount 当前 devcontainer 路径，因此没有运行 `docker compose up`；`docker build` 会把临时构建上下文传给 daemon，并由 Dockerfile 的 `COPY . .` 将源码复制进镜像，不依赖运行时 bind mount。HTTP 请求也从应用容器内部发出，避免把 daemon 主机端口误当成 devcontainer 本机端口。

```bash
set -eu

PHASE1_REPO_ROOT="$(git rev-parse --show-toplevel)"
PHASE1_TMP_PARENT=/tmp
PHASE1_TMP_ROOT="$(mktemp -d "$PHASE1_TMP_PARENT/azpanel-phase1.XXXXXXXX")"
PHASE1_SOURCE="$PHASE1_TMP_ROOT/source"
PHASE1_RUN_ID="${PHASE1_TMP_ROOT##*.}"
PHASE1_LABEL_KEY=org.azpanel.phase1
PHASE1_IMAGE=azpanel-phase1-app:local
PHASE1_NETWORK=azpanel-phase1-net
PHASE1_DB=azpanel-phase1-db
PHASE1_APP=azpanel-phase1-app-run

phase1_cleanup() {
  phase1_status=$?
  trap - EXIT INT TERM
  set +e
  cd "$PHASE1_REPO_ROOT"

  if [ "$(docker inspect --format '{{ index .Config.Labels "org.azpanel.phase1" }}' \
    "$PHASE1_APP" 2>/dev/null)" = "$PHASE1_RUN_ID" ]; then
    docker rm -f "$PHASE1_APP"
  fi
  if [ "$(docker inspect --format '{{ index .Config.Labels "org.azpanel.phase1" }}' \
    "$PHASE1_DB" 2>/dev/null)" = "$PHASE1_RUN_ID" ]; then
    docker rm -f "$PHASE1_DB"
  fi
  if [ "$(docker network inspect --format '{{ index .Labels "org.azpanel.phase1" }}' \
    "$PHASE1_NETWORK" 2>/dev/null)" = "$PHASE1_RUN_ID" ]; then
    docker network rm "$PHASE1_NETWORK"
  fi
  if [ "$(docker image inspect --format '{{ index .Config.Labels "org.azpanel.phase1" }}' \
    "$PHASE1_IMAGE" 2>/dev/null)" = "$PHASE1_RUN_ID" ]; then
    docker image rm "$PHASE1_IMAGE"
  fi

  case "${PHASE1_TMP_ROOT:-}" in
    "$PHASE1_TMP_PARENT"/azpanel-phase1.*)
      if [ -n "$PHASE1_TMP_ROOT" ] \
        && [ "$PHASE1_TMP_ROOT" != "$PHASE1_TMP_PARENT" ] \
        && [ -d "$PHASE1_TMP_ROOT" ]; then
        find "$PHASE1_TMP_ROOT" -mindepth 1 -delete
        rmdir "$PHASE1_TMP_ROOT"
      fi
      ;;
    *)
      printf 'refusing to clean unexpected path: %s\n' \
        "${PHASE1_TMP_ROOT:-<empty>}" >&2
      ;;
  esac
  exit "$phase1_status"
}
trap phase1_cleanup EXIT INT TERM

for phase1_container in "$PHASE1_APP" "$PHASE1_DB"; do
  if docker inspect "$phase1_container" >/dev/null 2>&1; then
    printf 'refusing to replace existing container: %s\n' "$phase1_container" >&2
    exit 1
  fi
done
if docker network inspect "$PHASE1_NETWORK" >/dev/null 2>&1; then
  printf 'refusing to replace existing network: %s\n' "$PHASE1_NETWORK" >&2
  exit 1
fi
if docker image inspect "$PHASE1_IMAGE" >/dev/null 2>&1; then
  printf 'refusing to replace existing image: %s\n' "$PHASE1_IMAGE" >&2
  exit 1
fi

mkdir "$PHASE1_SOURCE"
git archive HEAD | tar -x -C "$PHASE1_SOURCE"
cd "$PHASE1_SOURCE"
composer install
```

关键实际结果：临时根目录非空且匹配 `/tmp/azpanel-phase1.*`；验证只读取 `HEAD` 的归档内容。工作树中的现有 `.env`、`.docker.env` 和其他未提交文件不会被复制到临时源码，也不会被 cleanup 读取、覆盖或删除。

### Compose 配置解析

先创建仅用于插值和 `env_file` 校验的安全占位配置：

```bash
cat > .env <<'EOF'
DB_ROOT_PASSWORD=local-root-only
DB_DATABASE=azpanel_verify
DB_USERNAME=azpanel_verify
DB_PASSWORD=local-only
EOF

cat > .docker.env <<'EOF'
APP_DEBUG=true
DATABASE_TYPE=mysql
DATABASE_HOSTNAME=db
DATABASE_DATABASE=azpanel_verify
DATABASE_USERNAME=azpanel_verify
DATABASE_PASSWORD=local-only
DATABASE_HOSTPORT=3306
EOF

docker compose config
```

关键实际结果：首次在两个文件不存在时退出 1，并报告 `.docker.env` 不存在；使用以上占位文件后退出 0，输出 `app`、`web`、`db` 三个服务及完整 volumes/networks 配置。两个占位文件仅存在于临时源码目录，最终由经过路径校验的 cleanup trap 删除。

### 宿主开发服务器

宿主 `php think run` 未运行。当前宿主 PHP 8.2.32 缺少 `pdo_mysql`，不能提供认证页面的运行态证据；本复现实验不在宿主启动后台 PHP 进程，也不提供相应的启动或清理命令。运行态证据全部来自下方无 bind mount 的一次性应用镜像、MariaDB 和应用容器内 curl。

### 一次性应用与数据库

构建复制当前源码的本地镜像，创建专用 network 和 MariaDB：

```bash
docker build \
  --label "$PHASE1_LABEL_KEY=$PHASE1_RUN_ID" \
  --tag "$PHASE1_IMAGE" .
docker network create \
  --label "$PHASE1_LABEL_KEY=$PHASE1_RUN_ID" \
  "$PHASE1_NETWORK"

docker run --rm -d \
  --name "$PHASE1_DB" \
  --network "$PHASE1_NETWORK" \
  --label "$PHASE1_LABEL_KEY=$PHASE1_RUN_ID" \
  -e MARIADB_ROOT_PASSWORD=phase1-root \
  -e MARIADB_DATABASE=azpanel_phase1 \
  -e MARIADB_USER=azpanel_phase1 \
  -e MARIADB_PASSWORD=phase1-only \
  mariadb:10.11-jammy

for attempt in $(seq 1 30); do
  if docker exec azpanel-phase1-db \
    mariadb-admin ping -h 127.0.0.1 -uroot -pphase1-root --silent
  then
    break
  fi
  sleep 1
done

docker exec -i azpanel-phase1-db \
  mariadb -uazpanel_phase1 -pphase1-only azpanel_phase1 \
  < database/azure.sql
docker exec -i azpanel-phase1-db \
  mariadb -uazpanel_phase1 -pphase1-only azpanel_phase1 \
  < database/config.sql

docker exec -i azpanel-phase1-db \
  mariadb -uazpanel_phase1 -pphase1-only azpanel_phase1 <<'SQL'
INSERT INTO config (item,value,class,default_value,type) VALUES
  ('registration_verification_code','0','verification_code','0','bool'),
  ('login_verification_code','0','verification_code','0','bool'),
  ('reset_password_verification_code','0','verification_code','0','bool'),
  ('create_virtual_machine_verification_code','0','verification_code','0','bool'),
  ('captcha_provider','think-captcha','verification_code','think-captcha','string'),
  ('hcaptcha_site_key','','verification_code','','string'),
  ('hcaptcha_secret','','verification_code','','string'),
  ('custom_text','phase-1','custom','phase-1','string'),
  ('custom_script','','custom','','string');
UPDATE config SET value='127.0.0.1' WHERE item='smtp_host';
UPDATE config SET value='' WHERE item IN ('smtp_username','smtp_password');
UPDATE config SET value='1025' WHERE item='smtp_port';
UPDATE config SET value='AZPanel Phase 1' WHERE item='smtp_name';
UPDATE config SET value='noreply@example.test' WHERE item='smtp_sender';
SQL

docker run --rm -d \
  --name "$PHASE1_APP" \
  --network "$PHASE1_NETWORK" \
  --label "$PHASE1_LABEL_KEY=$PHASE1_RUN_ID" \
  -e APP_DEBUG=true \
  -e DATABASE_TYPE=mysql \
  -e DATABASE_HOSTNAME=azpanel-phase1-db \
  -e DATABASE_DATABASE=azpanel_phase1 \
  -e DATABASE_USERNAME=azpanel_phase1 \
  -e DATABASE_PASSWORD=phase1-only \
  -e DATABASE_HOSTPORT=3306 \
  "$PHASE1_IMAGE" \
  sh -lc 'python3 -m smtpd -n -c DebuggingServer 127.0.0.1:1025 & exec php think run --host 0.0.0.0 --port 8080'
```

关键实际结果：镜像构建退出 0；构建日志先显示 `COPY composer.json composer.lock ./`，随后显示 `Installing dependencies from lock file`、`90 installs, 0 updates, 0 removals`。MariaDB 就绪，应用容器日志显示 PHP 8.3.32 development server 启动。应用容器到 `azpanel-phase1-db:3306` 的连接成功；三个页面在应用容器内均返回 HTTP 200：

```bash
docker exec azpanel-phase1-app-run sh -lc '
for path in login register forget; do
  curl --silent --output /dev/null --write-out "/$path HTTP %{http_code}\n" \
    "http://127.0.0.1:8080/$path"
done
'
```

实际输出为 `/login HTTP 200`、`/register HTTP 200`、`/forget HTTP 200`。

### 登录场景

无图形验证码、错误密码与成功登录：

```bash
docker exec azpanel-phase1-db \
  mariadb -uazpanel_phase1 -pphase1-only azpanel_phase1 -e \
  "UPDATE config SET value='0' WHERE item='login_verification_code';
   UPDATE config SET value='think-captcha' WHERE item='captcha_provider';"

docker exec azpanel-phase1-app-run sh -lc \
  'curl --silent http://127.0.0.1:8080/login |
   grep -E "data-auth-login|name=\"email\"|name=\"password\"|name=\"code\"|name=\"hcaptcha_result\""'
docker exec azpanel-phase1-app-run sh -lc \
  'curl --silent http://127.0.0.1:8080/register |
   grep -E "data-auth-register|name=\"code\"|name=\"hcaptcha_result\""'
docker exec azpanel-phase1-app-run curl --silent -X POST \
  -d 'email=&password=' \
  http://127.0.0.1:8080/login
docker exec azpanel-phase1-app-run curl --silent -X POST \
  -d 'email=phase1@example.test&passwd=phase1-pass&repeat_passwd=phase1-pass&verify_code=&code=&hcaptcha_result=' \
  http://127.0.0.1:8080/register
docker exec azpanel-phase1-app-run curl --silent -X POST \
  -d 'email=phase1@example.test&password=wrong&code=&hcaptcha_result=' \
  http://127.0.0.1:8080/login
docker exec azpanel-phase1-app-run curl --silent -X POST \
  -d 'email=phase1@example.test&password=phase1-pass&code=&hcaptcha_result=' \
  http://127.0.0.1:8080/login
```

关键实际结果：登录页面包含邮箱、密码和 `data-auth-login`，但不含两类验证码字段；注册页面不含两类验证码字段；响应依次为“邮箱或密码不能为空”、“注册成功”、“密码不正确”和“登录成功”。

ThinkCaptcha 与 hCaptcha 条件分支：

```bash
docker exec azpanel-phase1-db \
  mariadb -uazpanel_phase1 -pphase1-only azpanel_phase1 -e \
  "UPDATE config SET value='1' WHERE item='login_verification_code';
   UPDATE config SET value='think-captcha' WHERE item='captcha_provider';"
docker exec azpanel-phase1-app-run sh -lc \
  'curl --silent http://127.0.0.1:8080/login |
   grep -E "name=\"code\"|/captcha"'
docker exec azpanel-phase1-app-run curl --silent -X POST \
  -d 'email=phase1@example.test&password=phase1-pass&code=wrong' \
  http://127.0.0.1:8080/login

docker exec azpanel-phase1-db \
  mariadb -uazpanel_phase1 -pphase1-only azpanel_phase1 -e \
  "UPDATE config SET value='hcaptcha' WHERE item='captcha_provider';"
docker exec azpanel-phase1-app-run sh -lc \
  'curl --silent http://127.0.0.1:8080/login |
   grep -E "name=\"hcaptcha_result\"|class=\"h-captcha\"|js.hcaptcha.com"'
docker exec azpanel-phase1-app-run curl --silent -X POST \
  -d 'email=phase1@example.test&password=phase1-pass&hcaptcha_result=' \
  http://127.0.0.1:8080/login
```

关键实际结果：两个 GET 分别渲染 `code`/captcha 图片和 `hcaptcha_result`/widget/官方脚本；POST 分别返回“验证码错误”和“请完成验证码”。未提交真实 hCaptcha token。

### 注册场景

关闭注册的页面与直接 POST：

```bash
docker exec azpanel-phase1-db \
  mariadb -uazpanel_phase1 -pphase1-only azpanel_phase1 -e \
  "UPDATE config SET value='0' WHERE item IN
     ('allow_public_reg','reg_email_veriy','registration_verification_code');"
docker exec azpanel-phase1-app-run sh -lc \
  'curl --silent http://127.0.0.1:8080/register |
   grep -E "管理员未开放公共注册|data-auth-register"'
docker exec azpanel-phase1-app-run curl --silent -X POST \
  -d 'email=closed@example.test&passwd=closed-pass&repeat_passwd=closed-pass&verify_code=&code=&hcaptcha_result=' \
  http://127.0.0.1:8080/register
```

关键实际结果：GET 显示关闭文案且无表单；直接 POST 却返回“注册成功”，因此该项未通过。

邮箱验证码请求与后续消费：

```bash
docker exec azpanel-phase1-db \
  mariadb -uazpanel_phase1 -pphase1-only azpanel_phase1 -e \
  "UPDATE config SET value='1' WHERE item IN ('allow_public_reg','reg_email_veriy');
   UPDATE config SET value='0' WHERE item='registration_verification_code';"
docker exec azpanel-phase1-app-run sh -lc \
  'curl --silent http://127.0.0.1:8080/register |
   grep -E "name=\"verify_code\"|data-request-code=\"/register/code\""'
docker exec azpanel-phase1-app-run sh -lc \
  'curl --silent --output /tmp/register-code-response --write-out "HTTP %{http_code}\n" \
   -X POST -d "email=regmail@example.test" \
   http://127.0.0.1:8080/register/code;
   grep -Eo "SMTP Error:[^<]+" /tmp/register-code-response | head -n 1'

register_code=$(docker exec azpanel-phase1-db \
  mariadb -N -uazpanel_phase1 -pphase1-only azpanel_phase1 -e \
  "SELECT code FROM verify WHERE email='regmail@example.test' ORDER BY id DESC LIMIT 1;")
docker exec azpanel-phase1-app-run curl --silent -X POST \
  --data-urlencode 'email=regmail@example.test' \
  --data-urlencode 'passwd=email-pass' \
  --data-urlencode 'repeat_passwd=email-pass' \
  --data-urlencode "verify_code=$register_code" \
  --data-urlencode 'code=' \
  --data-urlencode 'hcaptcha_result=' \
  http://127.0.0.1:8080/register
```

关键实际结果：本地 SMTP sink 因强制 STARTTLS 返回 HTTP 500；发送前写入的一次性验证码仍可被消费，注册 POST 返回“注册成功”。

两类图形验证码失败反馈：

```bash
docker exec azpanel-phase1-db \
  mariadb -uazpanel_phase1 -pphase1-only azpanel_phase1 -e \
  "UPDATE config SET value='0' WHERE item='reg_email_veriy';
   UPDATE config SET value='1' WHERE item='registration_verification_code';
   UPDATE config SET value='think-captcha' WHERE item='captcha_provider';"
docker exec azpanel-phase1-app-run sh -lc \
  'curl --silent http://127.0.0.1:8080/register |
   grep -E "name=\"code\"|/captcha"'
docker exec azpanel-phase1-app-run curl --silent -X POST \
  -d 'email=think@example.test&passwd=test-pass&repeat_passwd=test-pass&code=wrong' \
  http://127.0.0.1:8080/register

docker exec azpanel-phase1-db \
  mariadb -uazpanel_phase1 -pphase1-only azpanel_phase1 -e \
  "UPDATE config SET value='hcaptcha' WHERE item='captcha_provider';"
docker exec azpanel-phase1-app-run sh -lc \
  'curl --silent http://127.0.0.1:8080/register |
   grep -E "name=\"hcaptcha_result\"|class=\"h-captcha\"|js.hcaptcha.com"'
docker exec azpanel-phase1-app-run curl --silent -X POST \
  -d 'email=hcaptcha@example.test&passwd=test-pass&repeat_passwd=test-pass&hcaptcha_result=' \
  http://127.0.0.1:8080/register
```

关键实际结果分别为“图像验证码错误”和“请完成图像验证码填写”。

### 密码重置场景

```bash
docker exec azpanel-phase1-db \
  mariadb -uazpanel_phase1 -pphase1-only azpanel_phase1 -e \
  "UPDATE config SET value='1' WHERE item='reg_email_veriy';
   UPDATE config SET value='0' WHERE item IN
     ('login_verification_code','registration_verification_code');"
docker exec azpanel-phase1-app-run sh -lc \
  'curl --silent http://127.0.0.1:8080/forget |
   grep -E "data-auth-forget|name=\"email\"|name=\"passwd\"|name=\"repeat_passwd\"|name=\"verify_code\"|data-request-code=\"/forget/code\""'
docker exec azpanel-phase1-app-run sh -lc \
  'curl --silent --output /tmp/forget-code-response --write-out "HTTP %{http_code}\n" \
   -X POST -d "email=phase1@example.test" \
   http://127.0.0.1:8080/forget/code;
   grep -Eo "SMTP Error:[^<]+" /tmp/forget-code-response | head -n 1'

docker exec azpanel-phase1-app-run curl --silent -X POST \
  -d 'email=phase1@example.test&passwd=new-pass&repeat_passwd=other-pass&verify_code=wrong' \
  http://127.0.0.1:8080/forget
docker exec azpanel-phase1-app-run curl --silent -X POST \
  -d 'email=phase1@example.test&passwd=new-pass&repeat_passwd=new-pass&verify_code=wrong' \
  http://127.0.0.1:8080/forget

forget_code=$(docker exec azpanel-phase1-db \
  mariadb -N -uazpanel_phase1 -pphase1-only azpanel_phase1 -e \
  "SELECT code FROM verify WHERE email='phase1@example.test' ORDER BY id DESC LIMIT 1;")
docker exec azpanel-phase1-app-run curl --silent -X POST \
  --data-urlencode 'email=phase1@example.test' \
  --data-urlencode 'passwd=new-pass' \
  --data-urlencode 'repeat_passwd=new-pass' \
  --data-urlencode "verify_code=$forget_code" \
  http://127.0.0.1:8080/forget
docker exec azpanel-phase1-app-run curl --silent -X POST \
  -d 'email=phase1@example.test&password=new-pass' \
  http://127.0.0.1:8080/login
```

关键实际结果：验证码请求因本地 sink 的 STARTTLS 限制返回 HTTP 500；其余响应依次为“两次输入的密码不符”、“验证码不相符”、“重置成功”和“登录成功”。

退出同一个 shell session 即触发 cleanup trap；也可显式执行：

```bash
exit 0
```

## 认证冒烟检查

验证环境必须使用一次性数据库；仅可修改测试环境配置来切换验证码提供方，不触碰真实 Azure、邮件或 Telegram 资源。

宿主 PHP 8.2.32 缺少 `pdo_mysql`，因此宿主开发服务器未运行。运行态验证使用当前源码构建的一次性 `azpanel-phase1-app:local` 镜像（PHP 8.3.32、含 `pdo_mysql`），与一次性 MariaDB 10.11 容器置于专用 Docker network，并在应用容器内用 curl 访问开发服务器。没有 bind mount 工作树，也没有连接真实 Azure、邮件、Telegram 或 hCaptcha 服务。

| 页面 / 场景 | 操作与预期结果 | 状态 | 实际结果 / 证据 |
| --- | --- | --- | --- |
| 宿主 ThinkPHP 开发服务器 | 在宿主启动开发服务器 | 未运行 | 当前宿主 PHP 缺少 `pdo_mysql`；未提供或执行宿主后台启动命令。 |
| 一次性容器应用启动 | 启动带本次标签的应用和数据库容器后，请求 `/login`、`/register`、`/forget` 可获得应用响应 | 通过 | 应用容器日志显示 PHP 8.3.32 development server 启动；三个容器内 GET 均返回 HTTP 200。 |
| `/login` 基本字段 | 页面包含邮箱和密码字段，提交数据保留 legacy 字段名 | 通过 | GET 页面找到 `data-auth-login`、`name="email"`、`name="password"`；空字段 POST 返回“邮箱或密码不能为空”。 |
| `/login` 无图形验证码 | 测试配置关闭图形验证码时不要求验证码，失败响应可见且可再次提交 | 通过 | 页面不含 `code` 或 `hcaptcha_result`；错误密码返回“密码不正确”，同一环境正确密码随后返回“登录成功”。 |
| `/login` ThinkCaptcha | 测试配置选择 `think-captcha` 时显示并提交 `code`，错误验证码有失败反馈 | 通过 | 页面包含 `name="code"` 和 captcha 图片；错误值 POST 返回“验证码错误”。 |
| `/login` hCaptcha | 测试配置选择 `hcaptcha` 时显示并提交 `hcaptcha_result`，失败反馈可见 | 通过 | 页面包含隐藏字段、`.h-captcha` widget 和官方脚本；空 token POST 在本地短路并返回“请完成验证码”。未用真实 token 调用外部 siteverify。 |
| `/register` 关闭注册 | 测试配置关闭注册时拒绝访问或提交，并显示关闭状态 | 未通过 | GET 不含表单并显示“管理员未开放公共注册”；但直接 POST 仍返回“注册成功”并写入用户，关闭开关未在 `publicRegister` 中执行服务端拦截。 |
| `/register` 邮箱验证码 | 开启注册后可请求验证码；不发送到真实邮件服务 | 阻塞 | 页面字段和 `/register/code` 按钮存在；本地 SMTP sink 因应用对非 465 端口强制 STARTTLS 而返回 HTTP 500，不能把真实邮件投递写为通过。控制器在发送前写入的一次性验证码可被成功消费并标记 `result=1`，证明后续校验路径可运行。 |
| `/register` 无图形验证码 | 关闭图形验证码时注册请求不要求图形验证码字段 | 通过 | 页面不含两类图形验证码字段；隔离数据库中 POST 返回“注册成功”。 |
| `/register` ThinkCaptcha | 选择 `think-captcha` 时显示并提交 `code`，错误验证码有失败反馈 | 通过 | 页面包含 `name="code"` 和 captcha 图片；错误值 POST 返回“图像验证码错误”。 |
| `/register` hCaptcha | 选择 `hcaptcha` 时显示并提交 `hcaptcha_result`，失败反馈可见 | 通过 | 页面包含隐藏字段、widget 和官方脚本；空 token POST 在本地短路并返回“请完成图像验证码填写”。未用真实 token 调用外部 siteverify。 |
| `/register` 后端成功 | 一次性数据库和隔离通知配置下成功注册 | 通过 | 后端 POST 返回 `{"status":"1","title":"注册结果","content":"注册成功"}`。 |
| `/register` JS navigation 回调 | 成功响应后安排 1500ms navigation 回调 | 通过 | `register.test.mjs` 的 `setTimeout` stub 只在 `milliseconds === 1500` 时记录 callback；断言恰有一个 callback，执行后断言 `window.location.assign('/login')`。 |
| `/register` 真实浏览器跳转 | 浏览器中提交成功后实际跳转 `/login` | 未运行 | 当前环境没有可调用浏览器；后端 curl 与 Node 模块测试不能替代真实浏览器 navigation。 |
| `/forget` 验证码获取 | 可请求密码重置验证码；不发送到真实邮件服务 | 阻塞 | `/forget/code` 在一次性数据库写入有效 reset code，但本地 SMTP sink 因强制 STARTTLS 返回 HTTP 500；未连接真实邮件服务。请求按钮与失败恢复由 JS 测试覆盖。 |
| `/forget` 密码不一致 | 两次密码不一致时显示失败反馈并允许再次提交 | 通过 | POST 返回“两次输入的密码不符”；JS 测试验证失败后恢复提交按钮。 |
| `/forget` 错误验证码 | 错误邮箱验证码时显示失败反馈并允许再次提交 | 通过 | POST 返回“验证码不相符”；JS 测试验证失败反馈与按钮恢复。 |
| `/forget` 后端成功 | 一次性数据库验证码可完成重置，新密码可登录 | 通过 | POST 返回 `{"status":"1","title":"重置结果","content":"重置成功"}`，随后新密码登录返回“登录成功”。 |
| `/forget` JS navigation 回调 | 成功响应后安排 1500ms navigation 回调 | 通过 | `forget.test.mjs` 的 `setTimeout` stub 只在 `milliseconds === 1500` 时记录 callback；断言恰有一个 callback，执行后断言 `window.location.assign('/login')`。 |
| `/forget` 真实浏览器跳转 | 浏览器中重置成功后实际跳转 `/login` | 未运行 | 当前环境没有可调用浏览器；后端 curl 与 Node 模块测试不能替代真实浏览器 navigation。 |
| 响应式视口 | 在 `390×844`、`768×1024`、`1440×900` 检查三个页面，无不可达控件和意外横向滚动 | 未运行 | 当前环境未安装可调用的 Chromium/Chrome，也没有浏览器工具；未把模板检查或 Node DOM stub 冒充真实布局验证。 |

## 未覆盖的外部或浏览器验证

- 未运行宿主 ThinkPHP 开发服务器：当前宿主 PHP 缺少 `pdo_mysql`；运行态证据来自无 bind mount 的一次性应用和数据库容器。
- 未运行整套 `docker compose up`：Docker daemon 无法直接 bind mount 当前 devcontainer 工作树；仅运行了 `docker compose config` 和无 bind mount 的一次性镜像/数据库验证。
- 未验证真实 SMTP 投递、真实 hCaptcha 成功 token、Azure 或 Telegram；避免触碰真实凭据和资源。
- 未执行三个目标视口的真实浏览器布局检查；进入下一阶段前仍需在具备浏览器的环境补验。
- 功能顾虑：`allow_public_reg=0` 只影响注册页面，直接 `POST /register` 仍可创建用户。

## 第一阶段门禁

- `git diff origin/feature/docker-deployment...HEAD --check`：通过，退出码 0；另对当前未提交文档运行 `git diff --check`，同样退出 0。
- `git status --short`：提交前仅有 `README.md`、`docs/refactor/feature-matrix.md` 和 `docs/refactor/verification-phase-1.md` 三个预期文档；一次性 `.env`、`.docker.env`、容器、网络和本地应用镜像均已清理。
- `git log --oneline --max-count=8`：提交前显示阶段 1 的聚焦 feature/fix 提交；Task 8 提交后须再次运行并在任务报告记录结果。
- 在本门禁经审查前，不开始 user-shell 阶段。
