# 第一阶段验证

验证日期：2026-07-27。

验证状态使用 `通过`、`未通过`、`未运行` 或 `阻塞`。`通过` 必须有本次验证的实际命令或观察结果；依赖真实邮件、hCaptcha、Azure、Telegram 等外部服务的项目不得以替代检查冒充端到端通过。

## 验证清单

- `composer install`：从锁文件安装成功。
- `composer test`：PHPUnit 全部通过。
- `composer analyse`：不新增 PHPStan 错误。
- `npm run test:js`：请求层测试全部通过。
- `npm run check:js`：新 JS 文件语法全部有效。
- `php think run --host 127.0.0.1 --port 8080`：应用可启动。
- `/login`：邮箱、密码、两类验证码条件分支及失败反馈可用。
- `/register`：关闭注册、邮箱验证码、两类图形验证码及成功跳转可用。
- `/forget`：验证码获取、密码不一致、错误验证码及成功跳转可用。
- 视口 `390×844`、`768×1024`、`1440×900`：无不可达控件和意外横向滚动。

## 自动化验证

| 检查 | 命令 | 预期结果 | 状态 | 实际结果 / 证据 |
| --- | --- | --- | --- | --- |
| PHP 依赖 | `composer install` | 从 `composer.lock` 安装成功 | 通过 | 退出码 0；无需安装、更新或删除包，autoload、`service:discover` 和 `vendor:publish` 成功。 |
| PHP 测试 | `composer test` | PHPUnit 全部通过 | 通过 | 退出码 0；`OK (11 tests, 54 assertions)`。 |
| PHP 静态分析 | `composer analyse` | 不新增 PHPStan 错误 | 未通过 | 退出码 1；PHPStan 输出 `No rules detected`。当前 `phpstan.neon` 只有 `ignoreErrors`，没有规则级别或自定义 rules，因此本次不能证明“无新增错误”。 |
| JS 请求层测试 | `npm run test:js` | 请求层测试全部通过 | 通过 | 退出码 0；21 个测试通过，0 个失败。 |
| JS 语法 | `npm run check:js` | 新 JS 文件语法全部有效 | 通过 | 退出码 0；目标 JS 文件均通过 `node --check`。 |
| 部署脚本语法 | `bash -n deploy.sh` | shell 语法检查通过 | 通过 | 退出码 0，无输出。 |
| Compose 配置 | `docker compose config` | Compose 配置可解析 | 通过 | 首次因缺少本地 `.docker.env` 和数据库变量退出 1；创建仅用于验证的占位 `.env`、`.docker.env` 后，原命令退出 0 并输出完整配置。验证文件随后删除。未启动整套 Compose。 |

## 认证冒烟检查

启动命令：`php think run --host 127.0.0.1 --port 8080`

验证环境必须使用一次性数据库；仅可修改测试环境配置来切换验证码提供方，不触碰真实 Azure、邮件或 Telegram 资源。

本机命令使用 PHP 8.2.32，开发服务器确实启动并监听 `127.0.0.1:8080`；但本机 PHP 缺少 `pdo_mysql`，数据库页面返回 `could not find driver`。为继续安全验证，使用当前源码构建一次性 `azpanel-phase1-app:local` 镜像（PHP 8.3.32、含 `pdo_mysql`），与一次性 MariaDB 10.11 容器置于专用 Docker network，并在应用容器内用 curl 访问开发服务器。没有 bind mount 工作树，也没有连接真实 Azure、邮件、Telegram 或 hCaptcha 服务。

| 页面 / 场景 | 操作与预期结果 | 状态 | 实际结果 / 证据 |
| --- | --- | --- | --- |
| 应用启动 | 执行启动命令后，请求 `/login`、`/register`、`/forget` 可获得应用响应 | 通过 | 本机原命令显示 `ThinkPHP Development server is started`；含数据库驱动的隔离镜像中三个 GET 均返回 HTTP 200。 |
| `/login` 基本字段 | 页面包含邮箱和密码字段，提交数据保留 legacy 字段名 | 通过 | GET 页面找到 `data-auth-login`、`name="email"`、`name="password"`；空字段 POST 返回“邮箱或密码不能为空”。 |
| `/login` 无图形验证码 | 测试配置关闭图形验证码时不要求验证码，失败响应可见且可再次提交 | 通过 | 页面不含 `code` 或 `hcaptcha_result`；错误密码返回“密码不正确”，同一环境正确密码随后返回“登录成功”。 |
| `/login` ThinkCaptcha | 测试配置选择 `think-captcha` 时显示并提交 `code`，错误验证码有失败反馈 | 通过 | 页面包含 `name="code"` 和 captcha 图片；错误值 POST 返回“验证码错误”。 |
| `/login` hCaptcha | 测试配置选择 `hcaptcha` 时显示并提交 `hcaptcha_result`，失败反馈可见 | 通过 | 页面包含隐藏字段、`.h-captcha` widget 和官方脚本；空 token POST 在本地短路并返回“请完成验证码”。未用真实 token 调用外部 siteverify。 |
| `/register` 关闭注册 | 测试配置关闭注册时拒绝访问或提交，并显示关闭状态 | 未通过 | GET 不含表单并显示“管理员未开放公共注册”；但直接 POST 仍返回“注册成功”并写入用户，关闭开关未在 `publicRegister` 中执行服务端拦截。 |
| `/register` 邮箱验证码 | 开启注册后可请求验证码；不发送到真实邮件服务 | 阻塞 | 页面字段和 `/register/code` 按钮存在；本地 SMTP sink 因应用对非 465 端口强制 STARTTLS 而返回 HTTP 500，不能把真实邮件投递写为通过。控制器在发送前写入的一次性验证码可被成功消费并标记 `result=1`，证明后续校验路径可运行。 |
| `/register` 无图形验证码 | 关闭图形验证码时注册请求不要求图形验证码字段 | 通过 | 页面不含两类图形验证码字段；隔离数据库中 POST 返回“注册成功”。 |
| `/register` ThinkCaptcha | 选择 `think-captcha` 时显示并提交 `code`，错误验证码有失败反馈 | 通过 | 页面包含 `name="code"` 和 captcha 图片；错误值 POST 返回“图像验证码错误”。 |
| `/register` hCaptcha | 选择 `hcaptcha` 时显示并提交 `hcaptcha_result`，失败反馈可见 | 通过 | 页面包含隐藏字段、widget 和官方脚本；空 token POST 在本地短路并返回“请完成图像验证码填写”。未用真实 token 调用外部 siteverify。 |
| `/register` 成功跳转 | 一次性数据库和隔离通知配置下成功注册后跳转到预期页面 | 通过 | 后端 POST 返回“注册成功”；JS 测试验证成功响应后调用 `window.location.assign('/login')`。 |
| `/forget` 验证码获取 | 可请求密码重置验证码；不发送到真实邮件服务 | 阻塞 | `/forget/code` 在一次性数据库写入有效 reset code，但本地 SMTP sink 因强制 STARTTLS 返回 HTTP 500；未连接真实邮件服务。请求按钮与失败恢复由 JS 测试覆盖。 |
| `/forget` 密码不一致 | 两次密码不一致时显示失败反馈并允许再次提交 | 通过 | POST 返回“两次输入的密码不符”；JS 测试验证失败后恢复提交按钮。 |
| `/forget` 错误验证码 | 错误邮箱验证码时显示失败反馈并允许再次提交 | 通过 | POST 返回“验证码不相符”；JS 测试验证失败反馈与按钮恢复。 |
| `/forget` 成功跳转 | 一次性数据库和隔离通知配置下成功重置后跳转到预期页面 | 通过 | 使用一次性数据库验证码 POST 返回“重置成功”，随后新密码登录成功；JS 测试验证跳转 `/login`。 |
| 响应式视口 | 在 `390×844`、`768×1024`、`1440×900` 检查三个页面，无不可达控件和意外横向滚动 | 未运行 | 当前环境未安装可调用的 Chromium/Chrome，也没有浏览器工具；未把模板检查或 Node DOM stub 冒充真实布局验证。 |

## 未覆盖的外部或浏览器验证

- 未运行整套 `docker compose up`：Docker daemon 无法直接 bind mount 当前 devcontainer 工作树；仅运行了 `docker compose config` 和无 bind mount 的一次性镜像/数据库验证。
- 未验证真实 SMTP 投递、真实 hCaptcha 成功 token、Azure 或 Telegram；避免触碰真实凭据和资源。
- 未执行三个目标视口的真实浏览器布局检查；进入下一阶段前仍需在具备浏览器的环境补验。
- 功能顾虑：`allow_public_reg=0` 只影响注册页面，直接 `POST /register` 仍可创建用户。

## 第一阶段门禁

- `git diff origin/feature/docker-deployment...HEAD --check`：通过，退出码 0；另对当前未提交文档运行 `git diff --check`，同样退出 0。
- `git status --short`：提交前仅有 `README.md`、`docs/refactor/feature-matrix.md` 和 `docs/refactor/verification-phase-1.md` 三个预期文档；一次性 `.env`、`.docker.env`、容器、网络和本地应用镜像均已清理。
- `git log --oneline --max-count=8`：提交前显示阶段 1 的聚焦 feature/fix 提交；Task 8 提交后须再次运行并在任务报告记录结果。
- 在本门禁经审查前，不开始 user-shell 阶段。
