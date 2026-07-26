# Refactor Foundation and Authentication UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 建立全面重构所需的兼容测试与设计系统基座，并在不改变认证接口行为的前提下完成登录、注册和密码重置页面迁移。

**Architecture:** 应用继续使用 ThinkPHP 服务端渲染。PHPUnit 锁定 HTTP 源契约和模板结构，原生 ES Modules 提供统一的表单请求与反馈能力；认证 Controller 和数据库行为在本阶段保持不变，以便先建立可信基线。

**Tech Stack:** PHP 8.2+、ThinkPHP 8、PHPUnit 9.6、原生 CSS、原生 ES Modules、Node.js 18+ 内置测试运行器。

## Global Constraints

- 保持 `feature/docker-deployment` 当前路由路径、HTTP 方法、请求字段、响应字段和认证语义。
- 现有数据库无需迁移，且本阶段不得修改表结构。
- 页面继续由 ThinkPHP 服务端渲染，不引入 Vue、React、CSS 框架或前端打包器。
- 浅色主题为唯一交付主题；Token 必须允许后续增加深色主题。
- 模板默认转义；现有管理员自定义页脚脚本行为在本阶段保持兼容并记录为受信任管理员能力。
- 每项修改先写失败测试，再写最小实现；每个任务独立提交。
- 人类裁决（2026-07-27）：最低 PHP 版本提高至 8.2，以匹配锁定依赖的兼容性要求。

## Delivery Sequence

本计划是全面重构路线图的第一阶段。后续阶段严格按 `docs/superpowers/plans/2026-07-27-full-refactor-roadmap.md` 推进，并在前一阶段合并后各自生成详细计划。

## File Structure

- `phpunit.xml`：PHP 测试套件、缓存和覆盖范围配置。
- `tests/bootstrap.php`：加载 Composer 自动加载器并固定测试时区。
- `tests/Support/RouteSourceParser.php`：从当前路由源提取兼容契约，不参与生产运行。
- `tests/Fixtures/http-contract.php`：阶段开始时确认的路由、方法和 Controller 清单。
- `tests/Contract/HttpContractTest.php`：阻止路由和方法无意变化。
- `tests/Contract/AuthTemplateContractTest.php`：锁定认证表单字段、条件分支和入口。
- `docs/refactor/feature-matrix.md`：全量功能入口、所有者、风险和后续迁移状态。
- `public/static/css/app/tokens.css`：颜色、字体、间距、圆角、阴影、层级和断点 Token。
- `public/static/css/app/base.css`：全局排版、焦点、链接和页面背景。
- `public/static/css/app/components.css`：按钮、字段、卡片、提示、弹窗和状态组件。
- `public/static/css/app/auth.css`：认证页面布局。
- `public/static/css/app.css`：按固定顺序导入全部样式。
- `public/static/js/app/http.js`：表单编码、超时、HTTP 与旧 JSON 响应处理。
- `public/static/js/app/notice.js`：无障碍页面反馈和对话框。
- `public/static/js/auth/login.js`：登录页绑定。
- `public/static/js/auth/register.js`：注册与获取验证码绑定。
- `public/static/js/auth/forget.js`：密码重置与获取验证码绑定。
- `tests/js/http.test.mjs`：请求客户端纯逻辑测试。
- `app/view/auth/header.html`：认证页面文档头、品牌区和主内容开口。
- `app/view/auth/footer.html`：页脚、反馈对话框、管理员自定义内容和文档闭合。
- `app/view/auth/login.html`、`register.html`、`forget.html`：迁移后的三个认证页面。

---

### Task 1: Reproducible Test Toolchain

**Files:**
- Modify: `.gitignore`
- Modify: `composer.json`
- Create: `composer.lock`
- Create: `phpunit.xml`
- Create: `tests/bootstrap.php`
- Create: `tests/Contract/.gitkeep`

**Interfaces:**
- Consumes: PHP 8.2+ and the existing Composer project.
- Produces: `composer test`, `composer analyse`, and a committed dependency lock used by every later task.

- [ ] **Step 1: Write the initially failing PHPUnit configuration check**

Create `phpunit.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/9.6/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         colors="true"
         cacheResultFile="runtime/phpunit/result-cache">
    <testsuites>
        <testsuite name="azpanel">
            <directory>tests/Contract</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

Create `tests/bootstrap.php`:

```php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('Asia/Shanghai');
```

- [ ] **Step 2: Run the suite and verify the missing tool failure**

Run: `vendor/bin/phpunit --configuration phpunit.xml`

Expected: command fails because `vendor/bin/phpunit` does not exist.

- [ ] **Step 3: Add and lock the test dependency**

Remove the `composer.lock` line from `.gitignore`. Add `"phpunit/phpunit": "^9.6"` to `require-dev`, and add:

```json
"scripts": {
  "post-autoload-dump": [
    "@php think service:discover",
    "@php think vendor:publish"
  ],
  "test": "phpunit --configuration phpunit.xml",
  "analyse": "phpstan analyse app --memory-limit=1G"
}
```

Create the empty tracked file `tests/Contract/.gitkeep`, then run:

`composer update phpunit/phpunit --with-all-dependencies --no-scripts`

Expected: `composer.lock` and `vendor/bin/phpunit` are created without changing production package constraints beyond solver-required lock updates.

- [ ] **Step 4: Verify the empty suite and static analysis commands**

Run: `composer test`

Expected: PHPUnit starts successfully and reports no tests rather than a missing executable or bootstrap error.

Run: `composer analyse`

Expected: PHPStan executes with the existing `phpstan.neon`; record existing findings without broadening this task to fix unrelated code.

- [ ] **Step 5: Commit the test toolchain**

```bash
git add .gitignore composer.json composer.lock phpunit.xml tests/bootstrap.php tests/Contract/.gitkeep
git commit -m "test: add reproducible php test toolchain"
```

### Task 2: HTTP Compatibility Contract and Feature Matrix

**Files:**
- Create: `tests/Support/RouteSourceParser.php`
- Create: `tests/Fixtures/http-contract.php`
- Create: `tests/Contract/HttpContractTest.php`
- Create: `docs/refactor/feature-matrix.md`

**Interfaces:**
- Consumes: `route/app.php`.
- Produces: `RouteSourceParser::parse(string $source): array`, returning sorted records shaped as `['method' => string, 'path' => string, 'handler' => string]`; later plans must update the fixture only for explicitly approved contract changes.

- [ ] **Step 1: Write the failing parser and contract tests**

Create `tests/Contract/HttpContractTest.php`:

```php
<?php

declare(strict_types=1);

namespace tests\Contract;

use PHPUnit\Framework\TestCase;
use tests\Support\RouteSourceParser;

final class HttpContractTest extends TestCase
{
    public function testRouteSourceMatchesApprovedContract(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/route/app.php');
        self::assertIsString($source);
        self::assertSame(
            require dirname(__DIR__) . '/Fixtures/http-contract.php',
            RouteSourceParser::parse($source)
        );
    }

    public function testCriticalAuthenticationRoutesRemainStable(): void
    {
        $contract = require dirname(__DIR__) . '/Fixtures/http-contract.php';
        $keys = array_map(
            static fn (array $route): string => "{$route['method']} {$route['path']} {$route['handler']}",
            $contract
        );

        self::assertContains('GET / Auth/index', $keys);
        self::assertContains('POST /login Auth/login', $keys);
        self::assertContains('POST /register Auth/publicRegister', $keys);
        self::assertContains('POST /forget Auth/resetPassword', $keys);
        self::assertContains('POST /logout Auth/logout', $keys);
    }
}
```

- [ ] **Step 2: Run the contract test and verify it fails**

Run: `composer test -- --filter HttpContractTest`

Expected: FAIL because `tests\Support\RouteSourceParser` and the fixture do not exist.

- [ ] **Step 3: Implement the source parser**

Create `tests/Support/RouteSourceParser.php`:

```php
<?php

declare(strict_types=1);

namespace tests\Support;

final class RouteSourceParser
{
    /** @return list<array{method:string,path:string,handler:string}> */
    public static function parse(string $source): array
    {
        preg_match_all(
            "/Route::(get|head|post|put|patch|delete)\\(\\s*'([^']+)'\\s*,\\s*'([^']+)'/",
            $source,
            $matches,
            PREG_SET_ORDER
        );

        $routes = array_map(
            static fn (array $match): array => [
                'method' => strtoupper($match[1]),
                'path' => $match[2],
                'handler' => $match[3],
            ],
            $matches
        );

        preg_match_all(
            "/Route::resource\\(\\s*'([^']+)'\\s*,\\s*'([^']+)'/",
            $source,
            $resources,
            PREG_SET_ORDER
        );

        foreach ($resources as $resource) {
            $routes[] = [
                'method' => 'RESOURCE',
                'path' => $resource[1],
                'handler' => $resource[2],
            ];
        }

        usort($routes, static fn (array $a, array $b): int => [$a['path'], $a['method'], $a['handler']] <=> [$b['path'], $b['method'], $b['handler']]);

        return $routes;
    }
}
```

Add this test namespace to `composer.json`:

```json
"autoload-dev": {
  "psr-4": {
    "tests\\": "tests/"
  }
}
```

Run: `composer dump-autoload --no-scripts`

- [ ] **Step 4: Generate and review the approved route fixture**

Run:

```bash
php -r 'require "vendor/autoload.php"; $p=new tests\Support\RouteSourceParser(); var_export($p::parse(file_get_contents("route/app.php")));' > /tmp/azpanel-http-contract.txt
```

Create `tests/Fixtures/http-contract.php` with `<?php return ` followed by the reviewed exported array and a trailing semicolon. Confirm that all explicit routes and three resource declarations from `route/app.php` appear exactly once.

- [ ] **Step 5: Create the feature matrix with concrete ownership**

Create `docs/refactor/feature-matrix.md` with these top-level sections and populate every current route, Controller public action and one of the 54 HTML templates:

```markdown
# 功能兼容矩阵

| 领域 | 用户入口 | HTTP 契约 | Controller | 模板/响应 | 外部依赖 | 危险级别 | 迁移状态 |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 认证 | `/login` | `GET /login`, `POST /login` | `Auth::index/login` | `auth/login.html`, legacy JSON | Session、验证码、登录日志 | 中 | 基线 |
| 认证 | `/register` | `GET /register`, `POST /register/code`, `POST /register` | `Auth::registerIndex/registerCode/publicRegister` | `auth/register.html`, legacy JSON | 邮件、验证码 | 中 | 基线 |
| 认证 | `/forget` | `GET /forget`, `POST /forget/code`, `POST /forget` | `Auth::forgetIndex/forgetCode/resetPassword` | `auth/forget.html`, legacy JSON | 邮件、验证码 | 高 | 基线 |
```

Continue the table for user dashboard/profile, Azure accounts/resources, Azure VMs, firewall, traffic rules/logs, tasks, sharing/recycle bin, admin users/announcements/settings/logs, proxy, notifications, scheduled commands and Docker operations. Use `高` for destructive cloud/data operations, `中` for authentication/configuration changes, and `低` for read-only pages.

- [ ] **Step 6: Verify the baseline**

Run: `composer test -- --filter HttpContractTest`

Expected: 2 tests pass.

Run: `rg -L '迁移状态' docs/refactor/feature-matrix.md`

Expected: no output.

- [ ] **Step 7: Commit the compatibility baseline**

```bash
git add composer.json tests/Support tests/Fixtures tests/Contract docs/refactor/feature-matrix.md
git commit -m "test: lock current http and feature contracts"
```

### Task 3: Design Tokens and CSS Foundation

**Files:**
- Create: `public/static/css/app/tokens.css`
- Create: `public/static/css/app/base.css`
- Create: `public/static/css/app/components.css`
- Create: `public/static/css/app/auth.css`
- Create: `public/static/css/app.css`
- Create: `tests/Contract/AssetContractTest.php`

**Interfaces:**
- Consumes: no runtime dependency.
- Produces: stable `--az-*` CSS custom properties and reusable `.az-*` classes for all later page slices.

- [ ] **Step 1: Write the failing asset contract**

Create `tests/Contract/AssetContractTest.php`:

```php
<?php

declare(strict_types=1);

namespace tests\Contract;

use PHPUnit\Framework\TestCase;

final class AssetContractTest extends TestCase
{
    public function testAppStylesheetImportsLayersInStableOrder(): void
    {
        $css = file_get_contents(dirname(__DIR__, 2) . '/public/static/css/app.css');
        self::assertSame(
            "@import url('./app/tokens.css');\n"
            . "@import url('./app/base.css');\n"
            . "@import url('./app/components.css');\n"
            . "@import url('./app/auth.css');\n",
            $css
        );
    }

    public function testRequiredDesignTokensExist(): void
    {
        $css = file_get_contents(dirname(__DIR__, 2) . '/public/static/css/app/tokens.css');
        foreach (['--az-color-primary', '--az-color-danger', '--az-space-2', '--az-radius-md', '--az-shadow-sm', '--az-focus-ring'] as $token) {
            self::assertStringContainsString($token . ':', $css);
        }
    }
}
```

- [ ] **Step 2: Run the test and verify missing asset failures**

Run: `composer test -- --filter AssetContractTest`

Expected: FAIL because `public/static/css/app.css` and the token file do not exist.

- [ ] **Step 3: Implement the design Token layer**

Create `tokens.css` with the approved light palette and scale:

```css
:root {
  color-scheme: light;
  --az-color-primary: #315bd8;
  --az-color-primary-hover: #2549b8;
  --az-color-bg: #f6f8fc;
  --az-color-surface: #ffffff;
  --az-color-text: #1d2939;
  --az-color-muted: #667085;
  --az-color-border: #e4e7ec;
  --az-color-success: #16875b;
  --az-color-warning: #b54708;
  --az-color-danger: #c4320a;
  --az-space-1: 0.25rem;
  --az-space-2: 0.5rem;
  --az-space-3: 0.75rem;
  --az-space-4: 1rem;
  --az-space-6: 1.5rem;
  --az-space-8: 2rem;
  --az-radius-sm: 0.375rem;
  --az-radius-md: 0.625rem;
  --az-radius-lg: 0.875rem;
  --az-shadow-sm: 0 1px 2px rgb(16 24 40 / 6%);
  --az-shadow-md: 0 8px 24px rgb(16 24 40 / 10%);
  --az-focus-ring: 0 0 0 3px rgb(49 91 216 / 24%);
  --az-content-width: 72rem;
}
```

Create `app.css` with the exact import order asserted by the test. Implement `base.css`, `components.css`, and `auth.css` using only `--az-*` values. Required component selectors are `.az-button`, `.az-field`, `.az-input`, `.az-card`, `.az-alert`, `.az-dialog`, `.az-auth`, and `.az-auth__panel`. All interactive controls must define `:focus-visible`, `:disabled`, and busy states.

Use these minimum implementations, expanding only to cover the approved authentication mockup:

```css
/* base.css */
*, *::before, *::after { box-sizing: border-box; }
html { font-family: Inter, ui-sans-serif, system-ui, sans-serif; color: var(--az-color-text); background: var(--az-color-bg); }
body { min-height: 100vh; margin: 0; }
a { color: var(--az-color-primary); }
:focus-visible { outline: none; box-shadow: var(--az-focus-ring); }
.az-skip-link { position: fixed; left: var(--az-space-4); top: -4rem; z-index: 100; }
.az-skip-link:focus { top: var(--az-space-4); }

/* components.css */
.az-button { min-height: 2.75rem; border: 1px solid var(--az-color-border); border-radius: var(--az-radius-md); padding: 0 var(--az-space-4); background: var(--az-color-surface); color: var(--az-color-text); font: inherit; cursor: pointer; }
.az-button--primary { border-color: var(--az-color-primary); background: var(--az-color-primary); color: #fff; }
.az-button--primary:hover { background: var(--az-color-primary-hover); }
.az-button:disabled, .az-button[aria-busy="true"] { cursor: wait; opacity: .62; }
.az-field { display: grid; gap: var(--az-space-2); }
.az-input { width: 100%; min-height: 2.75rem; border: 1px solid var(--az-color-border); border-radius: var(--az-radius-md); padding: 0 var(--az-space-3); background: var(--az-color-surface); color: var(--az-color-text); font: inherit; }
.az-card { border: 1px solid var(--az-color-border); border-radius: var(--az-radius-lg); background: var(--az-color-surface); box-shadow: var(--az-shadow-md); }
.az-alert { border-radius: var(--az-radius-md); padding: var(--az-space-3); }
.az-dialog { max-width: 28rem; border: 0; border-radius: var(--az-radius-lg); padding: var(--az-space-6); box-shadow: var(--az-shadow-md); }

/* auth.css */
.az-auth { display: grid; min-height: calc(100vh - 4rem); place-items: center; padding: var(--az-space-6); }
.az-auth__panel { width: min(100%, 28rem); padding: var(--az-space-8); }
.az-auth__form { display: grid; gap: var(--az-space-4); }
.az-auth__footer { padding: var(--az-space-4); color: var(--az-color-muted); text-align: center; }
@media (max-width: 40rem) { .az-auth { padding: var(--az-space-3); } .az-auth__panel { padding: var(--az-space-6); } }
```

- [ ] **Step 4: Verify assets**

Run: `composer test -- --filter AssetContractTest`

Expected: 2 tests pass.

Run: `find public/static/css/app -name '*.css' -print0 | xargs -0 -n1 sh -c 'grep -q -- \"--az-\" \"$0\"'`

Expected: every stylesheet exits successfully because values come from the Token layer.

- [ ] **Step 5: Commit the CSS foundation**

```bash
git add public/static/css/app.css public/static/css/app tests/Contract/AssetContractTest.php
git commit -m "feat: add azpanel design system foundation"
```

### Task 4: Native HTTP and Notice Modules

**Files:**
- Create: `public/static/js/app/http.js`
- Create: `public/static/js/app/notice.js`
- Create: `tests/js/http.test.mjs`
- Create: `package.json`

**Interfaces:**
- Consumes: legacy JSON shaped as `{status: string|number, title: string, content: string}`.
- Produces: `postForm(url, fields, options): Promise<{status:string|number,title:string,content:string}>`, `HttpError`, `isSuccess(response): boolean`, and `showNotice(response): void`.

- [ ] **Step 1: Write failing Node tests for request compatibility**

Create `package.json`:

```json
{
  "private": true,
  "type": "module",
  "scripts": {
    "test:js": "node --test tests/js/*.test.mjs",
    "check:js": "find public/static/js -type f \\( -path '*/app/*.js' -o -path '*/auth/*.js' \\) -print0 | xargs -0 -r -n1 node --check"
  }
}
```

Create `tests/js/http.test.mjs`:

```js
import test from 'node:test';
import assert from 'node:assert/strict';
import { HttpError, isSuccess, postForm } from '../../public/static/js/app/http.js';

test('postForm preserves legacy form encoding and response fields', async () => {
  const fetchImpl = async (url, init) => {
    assert.equal(url, '/login');
    assert.equal(init.method, 'POST');
    assert.equal(init.body.toString(), 'email=a%40example.com&password=secret');
    return new Response(JSON.stringify({ status: '1', title: '登录成功', content: '欢迎回来' }), {
      status: 200,
      headers: { 'content-type': 'application/json' }
    });
  };

  const response = await postForm('/login', { email: 'a@example.com', password: 'secret' }, { fetchImpl });
  assert.equal(isSuccess(response), true);
  assert.equal(response.title, '登录成功');
});

test('postForm throws HttpError for non-json server failures', async () => {
  const fetchImpl = async () => new Response('Bad Gateway', { status: 502 });
  await assert.rejects(
    postForm('/login', {}, { fetchImpl }),
    error => error instanceof HttpError && error.status === 502
  );
});
```

- [ ] **Step 2: Run tests and verify missing module failure**

Run: `npm run test:js`

Expected: FAIL with module-not-found for `public/static/js/app/http.js`.

- [ ] **Step 3: Implement the minimal request module**

Create `public/static/js/app/http.js`:

```js
export class HttpError extends Error {
  constructor(message, { status = 0, cause } = {}) {
    super(message, { cause });
    this.name = 'HttpError';
    this.status = status;
  }
}

export function isSuccess(response) {
  return String(response?.status) === '1';
}

export async function postForm(url, fields, {
  fetchImpl = globalThis.fetch,
  timeoutMs = 15000
} = {}) {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), timeoutMs);

  try {
    const response = await fetchImpl(url, {
      method: 'POST',
      headers: { 'content-type': 'application/x-www-form-urlencoded;charset=UTF-8' },
      body: new URLSearchParams(Object.entries(fields).filter(([, value]) => value !== undefined)),
      credentials: 'same-origin',
      signal: controller.signal
    });
    const contentType = response.headers.get('content-type') ?? '';
    if (!response.ok || !contentType.includes('application/json')) {
      throw new HttpError('服务器暂时无法处理请求', { status: response.status });
    }
    return await response.json();
  } catch (error) {
    if (error instanceof HttpError) throw error;
    if (error?.name === 'AbortError') throw new HttpError('请求超时，请稍后重试', { cause: error });
    throw new HttpError('网络连接失败，请检查连接后重试', { cause: error });
  } finally {
    clearTimeout(timeout);
  }
}
```

Create `public/static/js/app/notice.js`:

```js
export function showNotice({ title = '提示', content = '' } = {}) {
  const dialog = document.querySelector('[data-notice-dialog]');
  const titleNode = dialog?.querySelector('[data-notice-title]');
  const contentNode = dialog?.querySelector('[data-notice-content]');
  if (!dialog || !titleNode || !contentNode) return;

  titleNode.textContent = String(title);
  contentNode.textContent = String(content);
  if (typeof dialog.showModal === 'function') {
    if (!dialog.open) dialog.showModal();
    return;
  }
  dialog.hidden = false;
}
```

- [ ] **Step 4: Verify JS behavior and syntax**

Run: `npm run test:js`

Expected: 2 tests pass.

Run: `npm run check:js`

Expected: all JS files pass syntax checking.

- [ ] **Step 5: Commit the browser foundation**

```bash
git add package.json public/static/js/app tests/js
git commit -m "feat: add native form request and notice modules"
```

### Task 5: Accessible Authentication Shell

**Files:**
- Modify: `app/view/auth/header.html`
- Modify: `app/view/auth/footer.html`
- Modify: `tests/Contract/AuthTemplateContractTest.php`

**Interfaces:**
- Consumes: existing `[title]` include parameter and `Config::obtain('custom_text/custom_script')`.
- Produces: `.az-auth` page shell plus `[data-notice-dialog]` consumed by `showNotice`.

- [ ] **Step 1: Write failing shell contract tests**

Create `tests/Contract/AuthTemplateContractTest.php`:

```php
<?php

declare(strict_types=1);

namespace tests\Contract;

use PHPUnit\Framework\TestCase;

final class AuthTemplateContractTest extends TestCase
{
    public function testAuthenticationShellLoadsOnlyNewSharedAssets(): void
    {
        $header = file_get_contents(dirname(__DIR__, 2) . '/app/view/auth/header.html');
        self::assertStringContainsString('lang="zh-CN"', $header);
        self::assertStringContainsString('/static/css/app.css', $header);
        self::assertStringNotContainsString('mdui', strtolower($header));
    }

    public function testFooterProvidesAccessibleNoticeDialog(): void
    {
        $footer = file_get_contents(dirname(__DIR__, 2) . '/app/view/auth/footer.html');
        self::assertStringContainsString('data-notice-dialog', $footer);
        self::assertStringContainsString('aria-live="polite"', $footer);
        self::assertStringContainsString("Config::obtain('custom_text')", $footer);
        self::assertStringContainsString("Config::obtain('custom_script')", $footer);
    }
}
```

- [ ] **Step 2: Run tests and verify legacy shell failures**

Run: `composer test -- --filter AuthTemplateContractTest`

Expected: FAIL because the old header uses `lang="cn"` and MDUI, and the footer has no notice dialog.

- [ ] **Step 3: Replace the authentication shell**

Rewrite `header.html` to contain `<!doctype html>`, `lang="zh-CN"`, separate charset and viewport meta elements, `[title]`, `/static/css/app.css`, a skip link, brand link, and `<main class="az-auth" id="main-content">`.

Rewrite `footer.html` to close the main element and provide:

```html
<dialog class="az-dialog" data-notice-dialog aria-labelledby="notice-title">
  <h2 id="notice-title" data-notice-title></h2>
  <p data-notice-content aria-live="polite"></p>
  <form method="dialog"><button class="az-button az-button--primary">知道了</button></form>
</dialog>
<footer class="az-auth__footer">
  {:app\\model\\Config::obtain('custom_text')}
</footer>
{:app\\model\\Config::obtain('custom_script')}
</body>
</html>
```

Load hCaptcha only inside templates whose configuration selects it, rather than globally in the shell.

- [ ] **Step 4: Verify the shell**

Run: `composer test -- --filter AuthTemplateContractTest`

Expected: shell tests pass; page-specific field assertions may remain failing until Tasks 6 and 7.

- [ ] **Step 5: Commit the shell**

```bash
git add app/view/auth/header.html app/view/auth/footer.html tests/Contract/AuthTemplateContractTest.php
git commit -m "feat: rebuild accessible authentication shell"
```

### Task 6: Login Page Migration

**Files:**
- Modify: `app/view/auth/login.html`
- Create: `public/static/js/auth/login.js`
- Modify: `tests/Contract/AuthTemplateContractTest.php`
- Modify: `tests/Fixtures/http-contract.php` only if formatting exposed a parser defect; no contract value may change.

**Interfaces:**
- Consumes: `POST /login` fields `code`, `email`, `password`, `hcaptcha_result` and legacy response fields.
- Produces: accessible login form; successful status redirects to `/user` after 1500 ms.

- [ ] **Step 1: Add failing login template assertions**

Add:

```php
public function testLoginFormPreservesFieldsAndEndpoint(): void
{
    $html = file_get_contents(dirname(__DIR__, 2) . '/app/view/auth/login.html');
    foreach (['name="email"', 'name="password"', 'name="code"', 'name="hcaptcha_result"'] as $field) {
        self::assertStringContainsString($field, $html);
    }
    self::assertStringContainsString('action="/login"', $html);
    self::assertStringContainsString('/static/js/auth/login.js', $html);
    self::assertStringNotContainsString('$.ajax', $html);
}
```

- [ ] **Step 2: Run and verify the field contract fails**

Run: `composer test -- --filter testLoginFormPreservesFieldsAndEndpoint`

Expected: FAIL because the old inputs use IDs without names and inline `$.ajax`.

- [ ] **Step 3: Implement the semantic login form and page module**

Use a real `<form action="/login" method="post" data-auth-login>`, visible labels, `autocomplete="email"` and `autocomplete="current-password"`, conditional captcha markup preserving both providers, a submit button with `data-submit`, and links to `/forget` and `/register`.

Implement `login.js`:

```js
import { HttpError, isSuccess, postForm } from '../app/http.js';
import { showNotice } from '../app/notice.js';

const form = document.querySelector('[data-auth-login]');

form?.addEventListener('submit', async event => {
  event.preventDefault();
  const submit = form.querySelector('[data-submit]');
  submit.disabled = true;
  submit.setAttribute('aria-busy', 'true');

  try {
    const fields = Object.fromEntries(new FormData(form));
    fields.hcaptcha_result = document.querySelector('[name="h-captcha-response"]')?.value ?? '';
    const response = await postForm(form.action, fields);
    showNotice(response);
    if (isSuccess(response)) setTimeout(() => window.location.assign('/user'), 1500);
  } catch (error) {
    showNotice({ title: '请求失败', content: error instanceof HttpError ? error.message : '发生未知错误' });
  } finally {
    submit.disabled = false;
    submit.removeAttribute('aria-busy');
  }
});
```

- [ ] **Step 4: Verify login contracts and JS**

Run: `composer test -- --filter AuthTemplateContractTest`

Expected: login and shell assertions pass.

Run: `npm run test:js && npm run check:js`

Expected: all JS tests and syntax checks pass.

- [ ] **Step 5: Commit login migration**

```bash
git add app/view/auth/login.html public/static/js/auth/login.js tests/Contract/AuthTemplateContractTest.php
git commit -m "feat: migrate login page to new design system"
```

### Task 7: Registration and Password Reset Migration

**Files:**
- Modify: `app/view/auth/register.html`
- Modify: `app/view/auth/forget.html`
- Create: `public/static/js/auth/register.js`
- Create: `public/static/js/auth/forget.js`
- Modify: `tests/Contract/AuthTemplateContractTest.php`

**Interfaces:**
- Consumes: `POST /register/code` field `email`; `POST /register` fields `code`, `email`, `passwd`, `verify_code`, `repeat_passwd`, `hcaptcha_result`; `POST /forget/code` field `email`; `POST /forget` fields `email`, `passwd`, `verify_code`, `repeat_passwd`.
- Produces: accessible forms preserving configuration-driven registration/captcha branches; successful registration/reset redirects to `/login` after 1500 ms.

- [ ] **Step 1: Add failing field and endpoint contract tests**

Add one data-provider test that asserts:

```php
yield 'register' => [
    'register.html',
    ['action="/register"', 'name="email"', 'name="passwd"', 'name="repeat_passwd"', 'name="verify_code"', 'name="code"', 'name="hcaptcha_result"', '/register/code']
];
yield 'forget' => [
    'forget.html',
    ['action="/forget"', 'name="email"', 'name="passwd"', 'name="repeat_passwd"', 'name="verify_code"', '/forget/code']
];
```

The test must also assert neither template contains `$.ajax`, `onclick=`, or a closing `</html>` before including `auth/footer.html`.

- [ ] **Step 2: Run and verify old templates fail**

Run: `composer test -- --filter AuthTemplateContractTest`

Expected: FAIL on missing `name` attributes and inline AJAX.

- [ ] **Step 3: Migrate both forms and modules**

Use the same semantic field and busy-state structure as login. Preserve `{if $register.allow_public_reg}`, email verification, ThinkCaptcha and hCaptcha branches exactly.

Implement `register.js` with two submit handlers: the main form posts all registered fields to `/register`; `[data-request-code]` posts `{email}` to `/register/code`. Implement `forget.js` with the equivalent `/forget` and `/forget/code` endpoints. Both use `postForm`, `showNotice`, and `isSuccess`; only successful main-form responses redirect.

Create `register.js`:

```js
import { HttpError, isSuccess, postForm } from '../app/http.js';
import { showNotice } from '../app/notice.js';

const form = document.querySelector('[data-auth-register]');
const requestCode = document.querySelector('[data-request-code]');

form?.addEventListener('submit', async event => {
  event.preventDefault();
  const submit = form.querySelector('[data-submit]');
  submit.disabled = true;
  try {
    const fields = Object.fromEntries(new FormData(form));
    fields.hcaptcha_result = document.querySelector('[name="h-captcha-response"]')?.value ?? '';
    const response = await postForm('/register', fields);
    showNotice(response);
    if (isSuccess(response)) setTimeout(() => window.location.assign('/login'), 1500);
  } catch (error) {
    showNotice({ title: '请求失败', content: error instanceof HttpError ? error.message : '发生未知错误' });
  } finally {
    submit.disabled = false;
  }
});

requestCode?.addEventListener('click', async () => {
  requestCode.disabled = true;
  try {
    showNotice(await postForm('/register/code', { email: form.elements.email.value }));
  } catch (error) {
    showNotice({ title: '请求失败', content: error instanceof HttpError ? error.message : '发生未知错误' });
  } finally {
    requestCode.disabled = false;
  }
});
```

Create `forget.js`:

```js
import { HttpError, isSuccess, postForm } from '../app/http.js';
import { showNotice } from '../app/notice.js';

const form = document.querySelector('[data-auth-forget]');
const requestCode = document.querySelector('[data-request-code]');

form?.addEventListener('submit', async event => {
  event.preventDefault();
  const submit = form.querySelector('[data-submit]');
  submit.disabled = true;
  try {
    const response = await postForm('/forget', Object.fromEntries(new FormData(form)));
    showNotice(response);
    if (isSuccess(response)) setTimeout(() => window.location.assign('/login'), 1500);
  } catch (error) {
    showNotice({ title: '请求失败', content: error instanceof HttpError ? error.message : '发生未知错误' });
  } finally {
    submit.disabled = false;
  }
});

requestCode?.addEventListener('click', async () => {
  requestCode.disabled = true;
  try {
    showNotice(await postForm('/forget/code', { email: form.elements.email.value }));
  } catch (error) {
    showNotice({ title: '请求失败', content: error instanceof HttpError ? error.message : '发生未知错误' });
  } finally {
    requestCode.disabled = false;
  }
});
```

- [ ] **Step 4: Verify all authentication contracts**

Run: `composer test -- --filter AuthTemplateContractTest`

Expected: all shell, login, registration and reset assertions pass.

Run: `npm run test:js && npm run check:js`

Expected: all tests and syntax checks pass.

- [ ] **Step 5: Commit registration and reset migration**

```bash
git add app/view/auth/register.html app/view/auth/forget.html public/static/js/auth/register.js public/static/js/auth/forget.js tests/Contract/AuthTemplateContractTest.php
git commit -m "feat: migrate registration and password reset pages"
```

### Task 8: Phase Verification and Operator Documentation

**Files:**
- Modify: `README.md`
- Modify: `docs/refactor/feature-matrix.md`
- Create: `docs/refactor/verification-phase-1.md`

**Interfaces:**
- Consumes: all deliverables from Tasks 1–7.
- Produces: reproducible phase verification and an explicit handoff to phase 2.

- [ ] **Step 1: Write the verification checklist before running it**

Create `docs/refactor/verification-phase-1.md` with exact commands and expected outcomes:

```markdown
# 第一阶段验证

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
```

- [ ] **Step 2: Run automated verification**

Run:

```bash
composer install
composer test
composer analyse
npm run test:js
npm run check:js
bash -n deploy.sh
docker compose config
```

Expected: all tests and syntax/config checks pass. Existing PHPStan findings, unavailable Docker daemon, or missing external service credentials must be recorded separately and must not be described as passing.

- [ ] **Step 3: Run authentication smoke checks**

Start the existing Docker deployment or ThinkPHP development server with a disposable database. Execute every manual case in `verification-phase-1.md`, including both captcha configurations by changing only test environment configuration. Record observed result and evidence next to each checklist item.

- [ ] **Step 4: Update documentation and migration status**

Add a README development section containing `composer install`, `composer test`, `composer analyse`, `npm run test:js`, and `npm run check:js`. In `feature-matrix.md`, change only the three authentication rows from `基线` to `已迁移（阶段 1）`; all other rows remain `基线`.

- [ ] **Step 5: Review the phase diff**

Run: `git diff origin/feature/docker-deployment...HEAD --check`

Expected: no whitespace errors.

Run: `git status --short`

Expected: only intentionally generated local files such as `.env`, runtime data, or `.superpowers/` remain untracked; no source file is accidentally omitted.

- [ ] **Step 6: Commit phase documentation**

```bash
git add README.md docs/refactor/feature-matrix.md docs/refactor/verification-phase-1.md
git commit -m "docs: record phase one verification"
```

- [ ] **Step 7: Final phase gate**

Run: `git log --oneline --max-count=8`

Expected: one focused commit for each completed task, with all phase verification commands passing or limitations explicitly documented. Do not begin the user-shell phase until this gate is reviewed.
