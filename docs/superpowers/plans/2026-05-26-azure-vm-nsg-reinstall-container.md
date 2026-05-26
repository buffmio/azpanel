# Azure VM NSG、系统重装与 Panel 单容器化 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 为 Azure VM 增加创建时 NSG 预设、详情页 NSG 规则管理、系统重装后删除旧系统盘，并交付只包含 Panel 的 PHP-FPM 单容器。

**Architecture:** 把 Azure NSG 规则生成、校验和系统重装请求体生成抽到小型服务类，控制器只负责权限、任务进度和调用 Azure API。容器化只提供应用运行层，nginx 与 MySQL/MariaDB 由用户外部配置，容器内用 supercronic 跑固定任务。

**Tech Stack:** ThinkPHP 8、PHP 8、Guzzle、Azure REST API、PHP-FPM、supercronic、Docker。

---

## 文件结构

- 新增 `app/service/AzureNetworkSecurityRuleService.php`：生成 NSG 预设规则、校验规则输入、把表单输入转换为 Azure securityRule 请求体。
- 新增 `app/service/AzureVirtualMachineReinstallService.php`：生成重装 VM 请求体，提取旧 OS Disk 信息，判断镜像类型。
- 修改 `app/controller/AzureApi.php`：增加 NSG 获取、创建、绑定、规则 CRUD、删除磁盘、删除 VM 但保留资源、重建 VM 等 Azure REST 封装。
- 修改 `app/controller/UserAzureServer.php`：创建 VM 时接入 NSG 预设；增加 NSG 规则管理接口；增加系统重装接口。
- 修改 `route/app.php`：新增 Azure VM NSG 和重装路由。
- 修改 `app/view/user/azure/server/create.html`：增加创建时 NSG 预设选择。
- 修改 `app/view/user/azure/server/read.html`：增加防火墙规则管理和系统重装 UI。
- 新增 `tests/Unit/AzureNetworkSecurityRuleServiceTest.php`：覆盖 NSG 预设和规则校验。
- 新增 `tests/Unit/AzureVirtualMachineReinstallServiceTest.php`：覆盖旧盘提取和新 VM 请求体关键字段。
- 修改 `composer.json`：增加测试脚本和 PHPUnit dev 依赖。
- 新增 `phpunit.xml`：配置测试启动。
- 新增 `Dockerfile`：Panel 单容器镜像。
- 新增 `docker/entrypoint.sh`：启动 php-fpm 与 supercronic。
- 新增 `docker/supercronic.cron`：固定定时任务。
- 新增 `docker/nginx-example.conf`：外部 nginx 示例。
- 新增 `.env.docker.example`：外部数据库连接示例。
- 修改 `README.md`：中文容器部署说明。

---

### Task 1: 测试基础设施

**Files:**
- Modify: `composer.json`
- Create: `phpunit.xml`
- Create: `tests/bootstrap.php`

- [ ] **Step 1: 在 `composer.json` 增加 PHPUnit 和测试脚本**

在 `require-dev` 中加入：

```json
"phpunit/phpunit": "^11.0"
```

在根级增加：

```json
"scripts": {
    "post-autoload-dump": [
        "@php think service:discover",
        "@php think vendor:publish"
    ],
    "test": "phpunit"
}
```

保留现有 `post-autoload-dump` 内容，只新增 `test` 脚本。

- [ ] **Step 2: 创建 `phpunit.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="tests/bootstrap.php" colors="true">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 3: 创建 `tests/bootstrap.php`**

```php
<?php

require __DIR__ . '/../vendor/autoload.php';
```

- [ ] **Step 4: 安装依赖**

Run: `composer update phpunit/phpunit --with-all-dependencies`

Expected: `composer.lock` 更新，`vendor/bin/phpunit` 可用。

- [ ] **Step 5: 运行空测试套件**

Run: `composer test`

Expected: PHPUnit 正常启动；如果因为没有测试返回 “No tests executed”，这是当前步骤可接受结果。

- [ ] **Step 6: 提交**

```bash
git add composer.json composer.lock phpunit.xml tests/bootstrap.php
git commit -m "test: add phpunit foundation"
```

---

### Task 2: NSG 预设规则服务

**Files:**
- Create: `app/service/AzureNetworkSecurityRuleService.php`
- Test: `tests/Unit/AzureNetworkSecurityRuleServiceTest.php`

- [ ] **Step 1: 写失败测试**

创建 `tests/Unit/AzureNetworkSecurityRuleServiceTest.php`：

```php
<?php

use app\service\AzureNetworkSecurityRuleService;
use PHPUnit\Framework\TestCase;

class AzureNetworkSecurityRuleServiceTest extends TestCase
{
    public function test_common_preset_for_linux_allows_ssh_http_and_https(): void
    {
        $rules = AzureNetworkSecurityRuleService::presetRules('common', false);

        $names = array_column($rules, 'name');
        $this->assertContains('allow_ssh', $names);
        $this->assertContains('allow_http', $names);
        $this->assertContains('allow_https', $names);
        $this->assertContains('allow_all_out', $names);
        $this->assertNotContains('allow_rdp', $names);
    }

    public function test_common_preset_for_windows_allows_rdp_http_and_https(): void
    {
        $rules = AzureNetworkSecurityRuleService::presetRules('common', true);

        $names = array_column($rules, 'name');
        $this->assertContains('allow_rdp', $names);
        $this->assertContains('allow_http', $names);
        $this->assertContains('allow_https', $names);
        $this->assertContains('allow_all_out', $names);
        $this->assertNotContains('allow_ssh', $names);
    }

    public function test_secure_preset_only_allows_ssh_for_linux(): void
    {
        $rules = AzureNetworkSecurityRuleService::presetRules('secure', false);

        $names = array_column($rules, 'name');
        $this->assertSame(['allow_ssh', 'allow_all_out'], $names);
    }

    public function test_all_preset_allows_every_inbound_port(): void
    {
        $rules = AzureNetworkSecurityRuleService::presetRules('all', false);

        $this->assertSame('allow_any_in', $rules[0]['name']);
        $this->assertSame('*', $rules[0]['properties']['destinationPortRange']);
        $this->assertSame('Inbound', $rules[0]['properties']['direction']);
    }
}
```

- [ ] **Step 2: 运行测试确认失败**

Run: `vendor/bin/phpunit tests/Unit/AzureNetworkSecurityRuleServiceTest.php`

Expected: FAIL，提示 `Class "app\service\AzureNetworkSecurityRuleService" not found`。

- [ ] **Step 3: 实现最小服务**

创建 `app/service/AzureNetworkSecurityRuleService.php`：

```php
<?php

namespace app\service;

class AzureNetworkSecurityRuleService
{
    public static function presetRules(string $preset, bool $isWindows): array
    {
        $rules = [];

        if ($preset === 'all') {
            $rules[] = self::rule('allow_any_in', 100, 'Inbound', '*', '*');
        } elseif ($preset === 'secure') {
            $rules[] = $isWindows
                ? self::rule('allow_rdp', 100, 'Inbound', 'Tcp', '3389')
                : self::rule('allow_ssh', 100, 'Inbound', 'Tcp', '22');
        } else {
            $rules[] = $isWindows
                ? self::rule('allow_rdp', 100, 'Inbound', 'Tcp', '3389')
                : self::rule('allow_ssh', 100, 'Inbound', 'Tcp', '22');
            $rules[] = self::rule('allow_http', 110, 'Inbound', 'Tcp', '80');
            $rules[] = self::rule('allow_https', 120, 'Inbound', 'Tcp', '443');
        }

        $rules[] = self::rule('allow_all_out', 4000, 'Outbound', '*', '*');

        return $rules;
    }

    private static function rule(string $name, int $priority, string $direction, string $protocol, string $destinationPort): array
    {
        return [
            'name' => $name,
            'properties' => [
                'protocol' => $protocol,
                'sourcePortRange' => '*',
                'destinationPortRange' => $destinationPort,
                'sourceAddressPrefix' => '*',
                'destinationAddressPrefix' => '*',
                'access' => 'Allow',
                'priority' => $priority,
                'direction' => $direction,
                'sourcePortRanges' => [],
                'destinationPortRanges' => [],
                'sourceAddressPrefixes' => [],
                'destinationAddressPrefixes' => [],
            ],
        ];
    }
}
```

- [ ] **Step 4: 运行测试确认通过**

Run: `vendor/bin/phpunit tests/Unit/AzureNetworkSecurityRuleServiceTest.php`

Expected: PASS。

- [ ] **Step 5: 提交**

```bash
git add app/service/AzureNetworkSecurityRuleService.php tests/Unit/AzureNetworkSecurityRuleServiceTest.php
git commit -m "feat: add Azure NSG preset rule service"
```

---

### Task 3: NSG 自定义规则校验和请求体

**Files:**
- Modify: `app/service/AzureNetworkSecurityRuleService.php`
- Test: `tests/Unit/AzureNetworkSecurityRuleServiceTest.php`

- [ ] **Step 1: 写失败测试**

追加到 `AzureNetworkSecurityRuleServiceTest`：

```php
public function test_build_rule_from_input_normalizes_security_rule_body(): void
{
    $rule = AzureNetworkSecurityRuleService::buildRuleFromInput([
        'name' => 'web_8080',
        'direction' => 'Inbound',
        'protocol' => 'Tcp',
        'source_address' => '*',
        'source_port' => '*',
        'destination_address' => '*',
        'destination_port' => '8080',
        'access' => 'Allow',
        'priority' => '300',
        'description' => 'web',
    ]);

    $this->assertSame('web_8080', $rule['name']);
    $this->assertSame(300, $rule['properties']['priority']);
    $this->assertSame('8080', $rule['properties']['destinationPortRange']);
    $this->assertSame('web', $rule['properties']['description']);
}

public function test_build_rule_rejects_invalid_priority(): void
{
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('优先级必须在 100 到 4096 之间');

    AzureNetworkSecurityRuleService::buildRuleFromInput([
        'name' => 'bad',
        'direction' => 'Inbound',
        'protocol' => 'Tcp',
        'source_address' => '*',
        'source_port' => '*',
        'destination_address' => '*',
        'destination_port' => '22',
        'access' => 'Allow',
        'priority' => '99',
        'description' => '',
    ]);
}
```

- [ ] **Step 2: 运行测试确认失败**

Run: `vendor/bin/phpunit tests/Unit/AzureNetworkSecurityRuleServiceTest.php`

Expected: FAIL，提示 `buildRuleFromInput` 不存在。

- [ ] **Step 3: 实现输入校验**

在服务类中加入：

```php
public static function buildRuleFromInput(array $input): array
{
    $name = trim((string) ($input['name'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_\-]+$/', $name)) {
        throw new \InvalidArgumentException('规则名称只允许字母、数字、下划线和中划线');
    }

    $direction = (string) ($input['direction'] ?? '');
    if (!in_array($direction, ['Inbound', 'Outbound'], true)) {
        throw new \InvalidArgumentException('方向必须是 Inbound 或 Outbound');
    }

    $protocol = (string) ($input['protocol'] ?? '');
    if (!in_array($protocol, ['Tcp', 'Udp', 'Icmp', '*'], true)) {
        throw new \InvalidArgumentException('协议必须是 Tcp、Udp、Icmp 或 *');
    }

    $access = (string) ($input['access'] ?? '');
    if (!in_array($access, ['Allow', 'Deny'], true)) {
        throw new \InvalidArgumentException('动作必须是 Allow 或 Deny');
    }

    $priority = (int) ($input['priority'] ?? 0);
    if ($priority < 100 || $priority > 4096) {
        throw new \InvalidArgumentException('优先级必须在 100 到 4096 之间');
    }

    return [
        'name' => $name,
        'properties' => [
            'protocol' => $protocol,
            'sourcePortRange' => trim((string) ($input['source_port'] ?? '*')) ?: '*',
            'destinationPortRange' => trim((string) ($input['destination_port'] ?? '*')) ?: '*',
            'sourceAddressPrefix' => trim((string) ($input['source_address'] ?? '*')) ?: '*',
            'destinationAddressPrefix' => trim((string) ($input['destination_address'] ?? '*')) ?: '*',
            'access' => $access,
            'priority' => $priority,
            'direction' => $direction,
            'description' => trim((string) ($input['description'] ?? '')),
            'sourcePortRanges' => [],
            'destinationPortRanges' => [],
            'sourceAddressPrefixes' => [],
            'destinationAddressPrefixes' => [],
        ],
    ];
}
```

- [ ] **Step 4: 运行测试确认通过**

Run: `vendor/bin/phpunit tests/Unit/AzureNetworkSecurityRuleServiceTest.php`

Expected: PASS。

- [ ] **Step 5: 提交**

```bash
git add app/service/AzureNetworkSecurityRuleService.php tests/Unit/AzureNetworkSecurityRuleServiceTest.php
git commit -m "feat: validate Azure NSG custom rules"
```

---

### Task 4: Azure API NSG 封装

**Files:**
- Modify: `app/controller/AzureApi.php`
- Modify: `app/controller/UserAzureServer.php`

- [ ] **Step 1: 修改 `AzureApi::createNetworkSecurityGroups` 签名**

把方法改为接收 `$security_rules`：

```php
public static function createNetworkSecurityGroups(
    $client,
    $account,
    $resource_group_name,
    $location,
    $name,
    array $security_rules
)
```

方法体中的 `securityRules` 使用传入参数：

```php
'securityRules' => $security_rules,
```

- [ ] **Step 2: 增加获取 NSG 名称方法**

在 `AzureApi` 中新增：

```php
public static function getNetworkSecurityGroupNameFromNetworkDetails(array $network_details): ?string
{
    $id = $network_details['properties']['networkSecurityGroup']['id'] ?? null;
    if ($id === null) {
        return null;
    }

    $parts = explode('/', $id);
    return end($parts) ?: null;
}
```

- [ ] **Step 3: 增加 NSG 详情和规则 CRUD**

在 `AzureApi` 中新增方法：

```php
public static function getNetworkSecurityGroup($account_id, $subscription_id, $resource_group_name, $nsg_name)
{
    $client = new Client();
    $url = 'https://management.azure.com/subscriptions/' . $subscription_id . '/resourceGroups/' . $resource_group_name . '/providers/Microsoft.Network/networkSecurityGroups/' . $nsg_name . '?api-version=2022-01-01';
    $result = $client->get($url, [
        'headers' => self::getToken($account_id, true),
    ]);

    return json_decode($result->getBody(), true);
}

public static function putNetworkSecurityRule($account_id, $subscription_id, $resource_group_name, $nsg_name, array $rule)
{
    $client = new Client();
    $url = 'https://management.azure.com/subscriptions/' . $subscription_id . '/resourceGroups/' . $resource_group_name . '/providers/Microsoft.Network/networkSecurityGroups/' . $nsg_name . '/securityRules/' . $rule['name'] . '?api-version=2022-01-01';
    $result = $client->put($url, [
        'headers' => self::getToken($account_id, true),
        'json' => ['properties' => $rule['properties']],
    ]);

    return json_decode($result->getBody(), true);
}

public static function deleteNetworkSecurityRule($account_id, $subscription_id, $resource_group_name, $nsg_name, $rule_name): void
{
    $client = new Client();
    $url = 'https://management.azure.com/subscriptions/' . $subscription_id . '/resourceGroups/' . $resource_group_name . '/providers/Microsoft.Network/networkSecurityGroups/' . $nsg_name . '/securityRules/' . $rule_name . '?api-version=2022-01-01';
    $client->delete($url, [
        'headers' => self::getToken($account_id, true),
    ]);
}
```

- [ ] **Step 4: 运行语法检查**

Run: `php -l app/controller/AzureApi.php`

Expected: `No syntax errors detected`。

- [ ] **Step 5: 提交**

```bash
git add app/controller/AzureApi.php
git commit -m "feat: add Azure NSG API helpers"
```

---

### Task 5: VM 创建流程接入 NSG 预设

**Files:**
- Modify: `app/controller/UserAzureServer.php`
- Modify: `app/view/user/azure/server/create.html`

- [ ] **Step 1: 修改创建页表单**

在 `create.html` 的 VM 网络/流量规则附近增加：

```html
<div class="mdui-col-md-6">
    <label class="mdui-textfield-label">防火墙规则</label>
    <select id="vm_nsg_preset" class="mdui-select" mdui-select>
        <option value="common" selected>常用端口</option>
        <option value="secure">只 SSH/RDP</option>
        <option value="all">全放端口</option>
    </select>
</div>
```

在 AJAX 提交数据中增加：

```javascript
vm_nsg_preset: $('#vm_nsg_preset').val(),
```

- [ ] **Step 2: 修改控制器读取预设**

在 `UserAzureServer::save()` 读取输入处增加：

```php
$vm_nsg_preset = input('vm_nsg_preset/s', 'common');
```

在记录 `$params['server']` 中增加：

```php
'nsg_preset' => $vm_nsg_preset,
```

- [ ] **Step 3: 调整创建步骤计数**

删除“只有 IPv6 才给 NSG 加步骤”的逻辑，改为始终为 NSG 创建增加 1 步；IPv6 只额外增加 IPv6 公网地址步骤。

- [ ] **Step 4: 始终创建 NSG**

在每台 VM 创建资源组后调用：

```php
$is_windows = Str::contains($vm_image, 'Win');
$security_rules = \app\service\AzureNetworkSecurityRuleService::presetRules($vm_nsg_preset, $is_windows);
$security_group_id = AzureApi::createNetworkSecurityGroups(
    $client,
    $account,
    $vm_resource_group_name,
    $vm_location,
    $security_group_name,
    $security_rules
);
```

- [ ] **Step 5: 修改网卡创建逻辑**

在 `AzureApi::createAzureVirtualNetworkInterfaces()` 中去掉 “只有 IPv6 时才绑定 NSG” 的条件，改为 `$security_group_id !== ''` 时绑定：

```php
if ($security_group_id !== '') {
    $body['properties']['networkSecurityGroup'] = [
        'id' => $security_group_id,
    ];
}
```

- [ ] **Step 6: 运行语法检查**

Run:

```bash
php -l app/controller/UserAzureServer.php
php -l app/controller/AzureApi.php
```

Expected: 两个文件都没有语法错误。

- [ ] **Step 7: 提交**

```bash
git add app/controller/UserAzureServer.php app/controller/AzureApi.php app/view/user/azure/server/create.html
git commit -m "feat: create Azure VMs with NSG presets"
```

---

### Task 6: VM 详情页 NSG 管理接口

**Files:**
- Modify: `route/app.php`
- Modify: `app/controller/UserAzureServer.php`

- [ ] **Step 1: 新增路由**

在 Azure 服务器路由附近增加：

```php
Route::get('/user/server/azure/nsg/:uuid',        'UserAzureServer/nsg');
Route::post('/user/server/azure/nsg/:uuid',       'UserAzureServer/saveNsgRule');
Route::delete('/user/server/azure/nsg/:uuid/:name', 'UserAzureServer/deleteNsgRule');
```

- [ ] **Step 2: 新增查找并确保 NSG 的私有方法**

在 `UserAzureServer` 中新增：

```php
private function ensureNetworkSecurityGroup(AzureServer $server): string
{
    $network_details = json_decode($server->network_details, true);
    $nsg_name = AzureApi::getNetworkSecurityGroupNameFromNetworkDetails($network_details);
    if ($nsg_name !== null) {
        return $nsg_name;
    }

    $client = new Client();
    $nsg_name = $server->name . '_security';
    $security_group_id = AzureApi::createNetworkSecurityGroups(
        $client,
        Azure::find($server->account_id),
        $server->resource_group,
        $server->location,
        $nsg_name,
        \app\service\AzureNetworkSecurityRuleService::presetRules('secure', Str::contains($server->os_offer, 'Windows'))
    );

    AzureApi::bindNetworkSecurityGroupToInterface($server, $security_group_id);
    $network_details = AzureApi::getAzureNetworkInterfacesDetails($server->account_id, $server->network_interfaces, $server->resource_group, $server->at_subscription_id);
    $server->network_details = json_encode($network_details);
    $server->save();

    return $nsg_name;
}
```

此步骤还需要在 Task 4 的 `AzureApi` 中补充 `bindNetworkSecurityGroupToInterface`。实现方式是读取当前网卡详情，保留 `ipConfigurations` 和 `enableAcceleratedNetworking`，设置 `networkSecurityGroup.id` 后 PUT 回网卡。

- [ ] **Step 3: 新增 `nsg` 方法**

```php
public function nsg($uuid)
{
    $server = AzureServer::where('user_id', session('user_id'))
        ->where('vm_id', $uuid)
        ->find();
    if ($server === null) {
        return json(Tools::msg('0', '查询失败', '虚拟机不存在'));
    }

    try {
        $nsg_name = $this->ensureNetworkSecurityGroup($server);
        $nsg = AzureApi::getNetworkSecurityGroup($server->account_id, $server->at_subscription_id, $server->resource_group, $nsg_name);
        return json(Tools::msg('1', '查询成功', $nsg['properties']['securityRules'] ?? []));
    } catch (\Exception $e) {
        return json(Tools::msg('0', '查询失败', $e->getMessage()));
    }
}
```

- [ ] **Step 4: 新增保存规则方法**

```php
public function saveNsgRule($uuid)
{
    $server = AzureServer::where('user_id', session('user_id'))
        ->where('vm_id', $uuid)
        ->find();
    if ($server === null) {
        return json(Tools::msg('0', '保存失败', '虚拟机不存在'));
    }

    try {
        $rule = \app\service\AzureNetworkSecurityRuleService::buildRuleFromInput(input('post.'));
        $nsg_name = $this->ensureNetworkSecurityGroup($server);
        AzureApi::putNetworkSecurityRule($server->account_id, $server->at_subscription_id, $server->resource_group, $nsg_name, $rule);
        return json(Tools::msg('1', '保存结果', '保存成功'));
    } catch (\Exception $e) {
        return json(Tools::msg('0', '保存失败', $e->getMessage()));
    }
}
```

- [ ] **Step 5: 新增删除规则方法**

```php
public function deleteNsgRule($uuid, $name)
{
    $server = AzureServer::where('user_id', session('user_id'))
        ->where('vm_id', $uuid)
        ->find();
    if ($server === null) {
        return json(Tools::msg('0', '删除失败', '虚拟机不存在'));
    }

    try {
        $nsg_name = $this->ensureNetworkSecurityGroup($server);
        AzureApi::deleteNetworkSecurityRule($server->account_id, $server->at_subscription_id, $server->resource_group, $nsg_name, $name);
        return json(Tools::msg('1', '删除结果', '删除成功'));
    } catch (\Exception $e) {
        return json(Tools::msg('0', '删除失败', $e->getMessage()));
    }
}
```

- [ ] **Step 6: 运行语法检查**

Run:

```bash
php -l route/app.php
php -l app/controller/UserAzureServer.php
php -l app/controller/AzureApi.php
```

Expected: 无语法错误。

- [ ] **Step 7: 提交**

```bash
git add route/app.php app/controller/UserAzureServer.php app/controller/AzureApi.php
git commit -m "feat: add Azure VM NSG rule endpoints"
```

---

### Task 7: VM 详情页 NSG 管理 UI

**Files:**
- Modify: `app/view/user/azure/server/read.html`

- [ ] **Step 1: 增加防火墙规则区域**

在 VM 详情页操作区域后增加一个面板，包含规则表格和表单。字段 ID 使用：

```text
nsg_name
nsg_direction
nsg_protocol
nsg_source_address
nsg_source_port
nsg_destination_address
nsg_destination_port
nsg_access
nsg_priority
nsg_description
```

- [ ] **Step 2: 增加加载规则 JS**

```javascript
function loadNsgRules() {
    $.ajax({
        url: "/user/server/azure/nsg/{$server->vm_id}",
        type: "GET",
        success: function (res) {
            if (res.status === 1 || res.status === '1') {
                var rows = '';
                $.each(res.content, function (_, rule) {
                    rows += '<tr>'
                        + '<td>' + rule.name + '</td>'
                        + '<td>' + rule.properties.direction + '</td>'
                        + '<td>' + rule.properties.protocol + '</td>'
                        + '<td>' + rule.properties.sourceAddressPrefix + '</td>'
                        + '<td>' + rule.properties.destinationPortRange + '</td>'
                        + '<td>' + rule.properties.access + '</td>'
                        + '<td>' + rule.properties.priority + '</td>'
                        + '<td><button class="mdui-btn mdui-color-red" onclick="deleteNsgRule(\\'' + rule.name + '\\')">删除</button></td>'
                        + '</tr>';
                });
                $('#nsg_rules').html(rows);
            } else {
                mdui.snackbar({message: res.content});
            }
        }
    });
}
```

- [ ] **Step 3: 增加保存规则 JS**

```javascript
function saveNsgRule() {
    $.ajax({
        url: "/user/server/azure/nsg/{$server->vm_id}",
        type: "POST",
        data: {
            name: $('#nsg_name').val(),
            direction: $('#nsg_direction').val(),
            protocol: $('#nsg_protocol').val(),
            source_address: $('#nsg_source_address').val(),
            source_port: $('#nsg_source_port').val(),
            destination_address: $('#nsg_destination_address').val(),
            destination_port: $('#nsg_destination_port').val(),
            access: $('#nsg_access').val(),
            priority: $('#nsg_priority').val(),
            description: $('#nsg_description').val()
        },
        success: function (res) {
            mdui.snackbar({message: res.content});
            if (res.status === 1 || res.status === '1') {
                loadNsgRules();
            }
        }
    });
}
```

- [ ] **Step 4: 增加删除规则 JS**

```javascript
function deleteNsgRule(name) {
    $.ajax({
        url: "/user/server/azure/nsg/{$server->vm_id}/" + name,
        type: "DELETE",
        success: function (res) {
            mdui.snackbar({message: res.content});
            if (res.status === 1 || res.status === '1') {
                loadNsgRules();
            }
        }
    });
}

loadNsgRules();
```

- [ ] **Step 5: 手动页面检查**

打开 VM 详情页，确认：

1. 防火墙区域不遮挡现有内容。
2. 表格在移动端可横向滚动。
3. 空规则列表时页面不报 JS 错误。

- [ ] **Step 6: 提交**

```bash
git add app/view/user/azure/server/read.html
git commit -m "feat: add Azure VM NSG rule UI"
```

---

### Task 8: 系统重装服务

**Files:**
- Create: `app/service/AzureVirtualMachineReinstallService.php`
- Test: `tests/Unit/AzureVirtualMachineReinstallServiceTest.php`

- [ ] **Step 1: 写失败测试**

创建 `tests/Unit/AzureVirtualMachineReinstallServiceTest.php`：

```php
<?php

use app\service\AzureVirtualMachineReinstallService;
use PHPUnit\Framework\TestCase;

class AzureVirtualMachineReinstallServiceTest extends TestCase
{
    public function test_extract_os_disk_returns_name_and_id(): void
    {
        $details = [
            'properties' => [
                'storageProfile' => [
                    'osDisk' => [
                        'name' => 'old-os-disk',
                        'managedDisk' => ['id' => '/subscriptions/1/resourceGroups/rg/providers/Microsoft.Compute/disks/old-os-disk'],
                    ],
                ],
            ],
        ];

        $disk = AzureVirtualMachineReinstallService::extractOsDisk($details);

        $this->assertSame('old-os-disk', $disk['name']);
        $this->assertStringContainsString('/disks/old-os-disk', $disk['id']);
    }

    public function test_extract_os_disk_requires_managed_disk_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AzureVirtualMachineReinstallService::extractOsDisk(['properties' => ['storageProfile' => ['osDisk' => []]]]);
    }
}
```

- [ ] **Step 2: 运行测试确认失败**

Run: `vendor/bin/phpunit tests/Unit/AzureVirtualMachineReinstallServiceTest.php`

Expected: FAIL，提示服务类不存在。

- [ ] **Step 3: 实现服务**

创建 `app/service/AzureVirtualMachineReinstallService.php`：

```php
<?php

namespace app\service;

class AzureVirtualMachineReinstallService
{
    public static function extractOsDisk(array $vmDetails): array
    {
        $osDisk = $vmDetails['properties']['storageProfile']['osDisk'] ?? [];
        $name = $osDisk['name'] ?? null;
        $id = $osDisk['managedDisk']['id'] ?? null;

        if ($name === null || $id === null) {
            throw new \InvalidArgumentException('无法读取原系统磁盘信息');
        }

        return [
            'name' => $name,
            'id' => $id,
        ];
    }
}
```

- [ ] **Step 4: 运行测试确认通过**

Run: `vendor/bin/phpunit tests/Unit/AzureVirtualMachineReinstallServiceTest.php`

Expected: PASS。

- [ ] **Step 5: 提交**

```bash
git add app/service/AzureVirtualMachineReinstallService.php tests/Unit/AzureVirtualMachineReinstallServiceTest.php
git commit -m "feat: add Azure VM reinstall service"
```

---

### Task 9: Azure API 系统重装封装

**Files:**
- Modify: `app/controller/AzureApi.php`

- [ ] **Step 1: 增加删除 VM 计算资源方法**

```php
public static function deleteVirtualMachineOnly($account_id, $request_url): void
{
    $client = new Client();
    $url = 'https://management.azure.com' . $request_url . '?api-version=2021-07-01';
    $client->delete($url, [
        'headers' => self::getToken($account_id, true),
    ]);
}
```

- [ ] **Step 2: 增加删除磁盘方法**

```php
public static function deleteManagedDisk($account_id, $disk_id): void
{
    $client = new Client();
    $url = 'https://management.azure.com' . $disk_id . '?api-version=2021-04-01';
    $client->delete($url, [
        'headers' => self::getToken($account_id, true),
    ]);
}
```

- [ ] **Step 3: 复用 `createAzureVm` 重建同名 VM**

确认 `createAzureVm` 已支持传入原网卡 ID。重装时使用原 `network_interfaces` 字段作为 `$interfaces` 参数，不创建新网卡。

- [ ] **Step 4: 运行语法检查**

Run: `php -l app/controller/AzureApi.php`

Expected: 无语法错误。

- [ ] **Step 5: 提交**

```bash
git add app/controller/AzureApi.php
git commit -m "feat: add Azure VM reinstall API helpers"
```

---

### Task 10: 系统重装控制器和 UI

**Files:**
- Modify: `route/app.php`
- Modify: `app/controller/UserAzureServer.php`
- Modify: `app/view/user/azure/server/read.html`

- [ ] **Step 1: 增加路由**

```php
Route::put('/user/server/azure/reinstall/:uuid', 'UserAzureServer/reinstall');
```

- [ ] **Step 2: VM 详情页分配镜像和 SSH key**

在 `UserAzureServer::read()` 中追加：

```php
View::assign('images', AzureList::images());
View::assign('ssh_key', SshKey::where('user_id', session('user_id'))->find());
```

- [ ] **Step 3: 增加控制器方法**

在 `UserAzureServer` 中新增 `reinstall($uuid)`，流程为：

```php
public function reinstall($uuid)
{
    $server = AzureServer::where('user_id', session('user_id'))
        ->where('vm_id', $uuid)
        ->find();
    if ($server === null) {
        return json(Tools::msg('0', '重装失败', '虚拟机不存在'));
    }

    $vm_image = input('vm_image/s');
    $vm_user = input('vm_user/s');
    $vm_passwd = input('vm_passwd/s');
    $vm_script = input('vm_script/s');
    $vm_ssh_key = (int) input('vm_ssh_key/s');
    $task_uuid = input('task_uuid/s');
    $vm_script = $vm_script === '' ? null : base64_encode($vm_script);

    $task_id = UserTask::create(session('user_id'), '重装虚拟机系统', [
        'vm_name' => $server->name,
        'image' => $vm_image,
    ], $task_uuid);

    try {
        $vmDetails = json_decode($server->vm_details, true);
        $oldDisk = \app\service\AzureVirtualMachineReinstallService::extractOsDisk($vmDetails);

        UserTask::update($task_id, 1 / 7, '正在释放虚拟机计算资源');
        AzureApi::virtualMachinesDeallocate($server->account_id, $server->request_url);

        UserTask::update($task_id, 2 / 7, '正在删除原虚拟机计算资源');
        AzureApi::deleteVirtualMachineOnly($server->account_id, $server->request_url);

        UserTask::update($task_id, 3 / 7, '正在创建新系统');
        AzureApi::createAzureVm(new Client(), Azure::find($server->account_id), $server->name, [
            'vm_size' => $server->vm_size,
            'vm_disk_size' => $server->disk_size,
            'vm_user' => $vm_user,
            'vm_passwd' => $vm_passwd,
            'vm_script' => $vm_script,
            'vm_ssh_key' => $vm_ssh_key,
        ], $vm_image, $server->network_interfaces, $server->location);

        UserTask::update($task_id, 4 / 7, '正在等待虚拟机启动');
        AzureApi::manageVirtualMachine('start', $server->account_id, $server->request_url);

        UserTask::update($task_id, 5 / 7, '正在刷新虚拟机信息');
        AzureApi::getAzureVirtualMachines($server->account_id);

        UserTask::update($task_id, 6 / 7, '正在删除原系统磁盘');
        AzureApi::deleteManagedDisk($server->account_id, $oldDisk['id']);

        UserTask::end($task_id, false);
        return json(Tools::msg('1', '重装结果', '重装成功'));
    } catch (\Exception $e) {
        UserTask::end($task_id, true, ['msg' => $e->getMessage()]);
        return json(Tools::msg('0', '重装失败', $e->getMessage()));
    }
}
```

在创建任务前加入与创建 VM 一致的校验代码：

```php
$prohibit_user = ['root', 'Admin', 'admin', 'centos', 'debian', 'ubuntu', 'administrator', 'test'];
if (!preg_match('/^[a-zA-Z0-9]+$/', $vm_user) || in_array($vm_user, $prohibit_user)) {
    return json(Tools::msg('0', '重装失败', '用户名只允许使用大小写字母与数字的组合，且不能使用常见用户名'));
}

$uppercase = preg_match('@[A-Z]@', $vm_passwd);
$lowercase = preg_match('@[a-z]@', $vm_passwd);
$number = preg_match('@[0-9]@', $vm_passwd);
if (!$uppercase || !$lowercase || !$number || strlen($vm_passwd) < 12 || strlen($vm_passwd) > 72) {
    return json(Tools::msg('0', '重装失败', '密码不符合要求，请阅读使用说明'));
}

$images = AzureList::images();
if (!isset($images[$vm_image])) {
    return json(Tools::msg('0', '重装失败', '系统镜像不存在'));
}

if (Str::contains($vm_image, 'Win') && !Str::contains($images[$vm_image]['sku'], 'smalldisk') && (int) $server->disk_size < 127) {
    return json(Tools::msg('0', '重装失败', '此 Windows 系统镜像要求硬盘大小不低于 127 GB'));
}
```

- [ ] **Step 4: 增加 UI 表单**

在 `read.html` 增加系统重装区域，包含镜像、用户名、密码、SSH key、脚本输入和确认按钮。按钮调用 `reinstall()`。

- [ ] **Step 5: 增加 JS**

```javascript
function reinstall() {
    var task_uuid = uuid();
    $.ajax({
        url: "/user/server/azure/reinstall/{$server->vm_id}",
        type: "PUT",
        data: {
            task_uuid: task_uuid,
            vm_image: $('#reinstall_image').val(),
            vm_user: $('#reinstall_user').val(),
            vm_passwd: $('#reinstall_passwd').val(),
            vm_ssh_key: $('#reinstall_ssh_key').val(),
            vm_script: $('#reinstall_script').val()
        },
        success: function (res) {
            mdui.snackbar({message: res.content});
        }
    });
}
```

- [ ] **Step 6: 运行语法检查**

Run:

```bash
php -l route/app.php
php -l app/controller/UserAzureServer.php
```

Expected: 无语法错误。

- [ ] **Step 7: 提交**

```bash
git add route/app.php app/controller/UserAzureServer.php app/view/user/azure/server/read.html
git commit -m "feat: add Azure VM system reinstall"
```

---

### Task 11: Panel 单容器化

**Files:**
- Create: `Dockerfile`
- Create: `docker/entrypoint.sh`
- Create: `docker/supercronic.cron`
- Create: `docker/nginx-example.conf`
- Create: `.env.docker.example`

- [ ] **Step 1: 创建 `Dockerfile`**

```dockerfile
FROM php:8.2-fpm-bookworm

ARG SUPERCRONIC_VERSION=v0.2.29

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev libpng-dev libonig-dev libicu-dev ca-certificates curl \
    && docker-php-ext-install pdo_mysql mbstring zip gd intl bcmath sockets pcntl \
    && curl -fsSL -o /usr/local/bin/supercronic https://github.com/aptible/supercronic/releases/download/${SUPERCRONIC_VERSION}/supercronic-linux-amd64 \
    && chmod +x /usr/local/bin/supercronic \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY . /app

RUN composer install --no-dev --optimize-autoloader \
    && chmod +x /app/think /app/docker/entrypoint.sh \
    && chown -R www-data:www-data /app/runtime

EXPOSE 9000

ENTRYPOINT ["/app/docker/entrypoint.sh"]
```

- [ ] **Step 2: 创建 `docker/entrypoint.sh`**

```sh
#!/bin/sh
set -e

if [ ! -f /app/.env ]; then
    echo "未找到 /app/.env，请挂载或复制 .env 后再启动容器。"
fi

mkdir -p /app/runtime
chown -R www-data:www-data /app/runtime

supercronic /app/docker/supercronic.cron &
exec php-fpm
```

- [ ] **Step 3: 创建 `docker/supercronic.cron`**

```cron
0 0 * * * php /app/think tools --action statisticsTraffic
0 * * * * php /app/think autoRefreshAccount
0 * * * * php /app/think closeTimeoutTask
0 * * * * php /app/think trafficControlStop
*/5 * * * * php /app/think trafficControlStart
```

- [ ] **Step 4: 创建 `.env.docker.example`**

```env
APP_DEBUG=false

DATABASE_TYPE=mysql
DATABASE_HOST=127.0.0.1
DATABASE_PORT=3306
DATABASE_NAME=azpanel
DATABASE_USER=azpanel
DATABASE_PASSWORD=change-me
DATABASE_CHARSET=utf8mb4
DATABASE_PREFIX=
```

- [ ] **Step 5: 创建 `docker/nginx-example.conf`**

```nginx
server {
    listen 80;
    server_name azpanel.example.com;
    root /path/to/azpanel/public;
    index index.php index.html;

    location / {
        if (!-e $request_filename) {
            rewrite ^(.*)$ /index.php?s=$1 last;
            break;
        }
    }

    location ~ \.php$ {
        fastcgi_pass azpanel:9000;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /app/public$fastcgi_script_name;
    }
}
```

- [ ] **Step 6: 构建镜像**

Run: `docker build -t azpanel:local .`

Expected: 镜像构建成功。

- [ ] **Step 7: 提交**

```bash
git add Dockerfile docker/entrypoint.sh docker/supercronic.cron docker/nginx-example.conf .env.docker.example
git commit -m "feat: add standalone panel container"
```

---

### Task 12: 中文 README 容器部署说明

**Files:**
- Modify: `README.md`

- [ ] **Step 1: 增加部署说明**

在 README 中追加：

```markdown
## 单容器部署

本项目提供 Panel 应用容器。容器只包含 PHP-FPM、PHP CLI、Composer 依赖、项目代码和 supercronic 定时任务，不包含 nginx 和 MySQL/MariaDB。

用户需要自行准备：

1. nginx。
2. MySQL 或 MariaDB。
3. 已创建的数据库。
4. 已导入的 `database/azure.sql` 和 `database/config.sql`。

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

```bash
docker run -d --name azpanel \
  --restart unless-stopped \
  -v $(pwd)/.env:/app/.env \
  -v $(pwd)/runtime:/app/runtime \
  azpanel:local
```

容器内默认启动 `php-fpm` 和 supercronic。固定定时任务位于 `docker/supercronic.cron`。

### nginx

nginx 由用户自行配置。可参考 `docker/nginx-example.conf`，把 PHP 请求转发到 Panel 容器的 `9000` 端口。
```

- [ ] **Step 2: 检查 Markdown**

Run: `git diff -- README.md`

Expected: Markdown 代码块闭合，说明明确写出 nginx 和 MySQL/MariaDB 外置。

- [ ] **Step 3: 提交**

```bash
git add README.md
git commit -m "docs: add standalone container deployment guide"
```

---

### Task 13: 最终验证

**Files:**
- All modified files

- [ ] **Step 1: 运行 PHP 单测**

Run: `composer test`

Expected: PASS。

- [ ] **Step 2: 运行 PHP 语法检查**

Run:

```bash
php -l app/service/AzureNetworkSecurityRuleService.php
php -l app/service/AzureVirtualMachineReinstallService.php
php -l app/controller/AzureApi.php
php -l app/controller/UserAzureServer.php
php -l route/app.php
```

Expected: 全部 `No syntax errors detected`。

- [ ] **Step 3: 构建 Docker 镜像**

Run: `docker build -t azpanel:local .`

Expected: 构建成功。

- [ ] **Step 4: 检查 Git 状态**

Run: `git status --short`

Expected: 没有未提交变更。

- [ ] **Step 5: 准备人工验证说明**

记录需要真实 Azure 账户验证的路径：

1. 创建 Linux VM，选择常用端口，确认 NSG 规则为 SSH/HTTP/HTTPS/出站全放。
2. 创建 Windows VM，选择只 SSH/RDP，确认 NSG 规则为 RDP/出站全放。
3. 在 VM 详情页新增、编辑、删除 NSG 入站规则。
4. 对一台测试 VM 执行系统重装，确认新 VM running 后旧 OS Disk 被删除。
5. 用外部 nginx + 外部 MySQL/MariaDB 连接 Panel 容器，确认页面可访问，容器日志显示 supercronic 已加载固定任务。
