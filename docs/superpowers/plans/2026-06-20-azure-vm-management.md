# Azure VM Management Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Azure VM reinstall with rollback and cleanup, credential reset with SSH password-auth handling, full NSG firewall rule management, and remove the AWS feature surface.

**Architecture:** Keep the existing ThinkPHP pattern: `UserAzureServer` validates requests and coordinates tasks, while `AzureApi` wraps Azure REST calls. UI changes stay in the existing Azure VM detail/list templates, and AWS removal is limited to routes, menus, code, views, and Composer dependency while preserving database tables.

**Tech Stack:** PHP 8, ThinkPHP 8, think-orm, Guzzle, MDUI, jQuery, Azure Resource Manager REST API.

---

## UI Style Constraint

All new UI must strictly match the existing azpanel style. Use only the patterns already present in `app/view/user/azure/server/read.html`, `app/view/user/azure/server/index.html`, and `app/view/user/header.html`:

- MDUI cards with `mdui-card` and `mdui-card-content`.
- Existing grid classes such as `mdui-row`, `mdui-col-md-*`, and `mdui-col-sm-12`.
- Existing MDUI form controls: `mdui-textfield`, `mdui-select`, `mdui-switch`, `mdui-table`, `mdui-dialog`.
- Existing button style: dense raised `mdui-btn mdui-btn-raised mdui-btn-dense mdui-ripple mdui-color-blue-grey`.
- Existing section title style: `style="color: #3F51B5; font-size: 18px"` inside cards.
- Existing page title style: `style="color: #3F51B5; font-size: 34px"` if a new page is created.
- Existing interactions: `mdui.confirm`, `mdui.prompt`, `mdui.alert`, jQuery AJAX, and the current task progress dialog.

Do not add new CSS frameworks, new icon libraries, custom gradients, new color palettes, oversized headings, landing-page sections, decorative cards, nested cards, or explanatory tutorial copy. The new controls should look like native additions to the current VM detail page.

## File Structure

- Modify `composer.json`: remove `aws/aws-sdk-php`.
- Modify `route/app.php`: remove AWS routes and add Azure VM operation routes.
- Modify `app/controller/AzureApi.php`: add focused Azure helpers for disks, VM model updates, VM run command, VM extensions, NSG, NIC, and security rules.
- Modify `app/controller/UserAzureServer.php`: add validation helpers and action methods for reimage, credentials, and firewall rules.
- Modify `app/view/user/header.html`: remove AWS navigation.
- Modify `app/view/user/azure/server/index.html`: add one "虚拟机详情" menu entry only; do not add separate reinstall, credential, or firewall entries to the list menu.
- Modify `app/view/user/azure/server/read.html`: add forms and scripts for reinstall, credential reset, and firewall rule management.
- Delete AWS code files:
  - `app/controller/UserAws.php`
  - `app/controller/UserAwsServer.php`
  - `app/controller/AwsApi.php`
  - `app/controller/AwsList.php`
  - `app/model/Aws.php`
  - `app/view/user/aws/`
- Keep `database/migrations/20230811011410_aws_account_table.php`.

## Shared Validation Snippets

Use these helpers in `UserAzureServer` when implementing tasks below:

```php
private function findOwnedServer(string $uuid): ?AzureServer
{
    return AzureServer::where('user_id', session('user_id'))
        ->where('vm_id', $uuid)
        ->find();
}

private function validateVmUsername(string $username): ?string
{
    $prohibit_user = ['root', 'Admin', 'admin', 'centos', 'debian', 'ubuntu', 'administrator', 'test'];
    if (!preg_match('/^[a-zA-Z0-9]+$/', $username) || in_array($username, $prohibit_user, true)) {
        return '用户名只允许使用大小写字母与数字的组合，且不能使用常见用户名';
    }

    return null;
}

private function validateVmPassword(string $password): ?string
{
    $uppercase = preg_match('@[A-Z]@', $password);
    $lowercase = preg_match('@[a-z]@', $password);
    $number = preg_match('@[0-9]@', $password);

    if (!$uppercase || !$lowercase || !$number || strlen($password) < 12 || strlen($password) > 72) {
        return '密码不符合要求，请阅读使用说明';
    }

    return null;
}

private function azureErrorMessage(\Throwable $e): string
{
    if (method_exists($e, 'getResponse') && $e->getResponse() !== null) {
        return $e->getResponse()->getBody()->getContents();
    }

    return $e->getMessage();
}
```

---

### Task 1: Remove AWS Feature Surface

**Files:**
- Modify: `composer.json`
- Modify: `route/app.php`
- Modify: `app/view/user/header.html`
- Delete: `app/controller/UserAws.php`
- Delete: `app/controller/UserAwsServer.php`
- Delete: `app/controller/AwsApi.php`
- Delete: `app/controller/AwsList.php`
- Delete: `app/model/Aws.php`
- Delete: `app/view/user/aws/`

- [ ] **Step 1: Remove AWS dependency from Composer**

Edit `composer.json` and remove this line from `require`:

```json
"aws/aws-sdk-php": "^3.277",
```

- [ ] **Step 2: Remove AWS routes**

In `route/app.php`, delete the AWS route block:

```php
// Aws 账户
Route::resource('/user/aws',                      'UserAws');
Route::post('/user/aws/search',                   'UserAws/searchAccount');

// Aws 服务器
Route::resource('/user/server/aws',               'UserAwsServer');
```

- [ ] **Step 3: Remove AWS navigation**

In `app/view/user/header.html`, delete the two AWS collapse sections: `AWS 账户` and `AWS 虚拟机`. Also delete the divider immediately before `AWS 账户`, leaving the divider before `流量控制` in place.

- [ ] **Step 4: Delete AWS PHP and view files**

Run:

```bash
rm -rf app/view/user/aws \
  app/controller/UserAws.php \
  app/controller/UserAwsServer.php \
  app/controller/AwsApi.php \
  app/controller/AwsList.php \
  app/model/Aws.php
```

- [ ] **Step 5: Verify AWS references are not callable**

Run:

```bash
rg -n "Route::.*aws|/user/aws|/user/server/aws|UserAws|UserAwsServer|AwsApi|AwsList|app\\\\model\\\\Aws|aws/aws-sdk-php" composer.json route app
```

Expected: no matches except lowercase `aws` inside unrelated license text if the search scope is expanded beyond `app`, `route`, and `composer.json`.

- [ ] **Step 6: PHP syntax check remaining touched files**

Run:

```bash
php -l route/app.php
php -l app/controller/UserAzureServer.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 7: Commit**

```bash
git add composer.json route/app.php app/view/user/header.html app/controller app/model app/view/user
git commit -m "refactor: remove aws feature surface"
```

---

### Task 2: Add AzureApi REST Helpers

**Files:**
- Modify: `app/controller/AzureApi.php`

- [ ] **Step 1: Add disk and VM model helpers**

Add these methods near existing VM helper methods in `AzureApi`:

```php
public static function getVirtualMachine($account_id, $request_url): array
{
    $client = new Client();
    $url = 'https://management.azure.com' . $request_url . '?api-version=2021-07-01';
    $result = $client->get($url, [
        'headers' => self::getToken($account_id, true),
    ]);

    return json_decode($result->getBody(), true);
}

public static function createManagedDiskFromImage($account_id, $subscription_id, $resource_group, $location, $disk_name, array $image, $disk_size, $storage_account_type = 'Standard_LRS'): array
{
    $body = [
        'location' => $location,
        'sku' => [
            'name' => $storage_account_type,
        ],
        'properties' => [
            'creationData' => [
                'createOption' => 'FromImage',
                'imageReference' => [
                    'id' => '/Subscriptions/' . $subscription_id . '/Providers/Microsoft.Compute/Locations/' . $location . '/Publishers/' . $image['publisher'] . '/ArtifactTypes/VMImage/Offers/' . $image['offer'] . '/Skus/' . $image['sku'] . '/Versions/' . $image['version'],
                ],
            ],
            'diskSizeGB' => (int) $disk_size,
        ],
    ];

    $client = new Client();
    $url = 'https://management.azure.com/subscriptions/' . $subscription_id . '/resourceGroups/' . $resource_group . '/providers/Microsoft.Compute/disks/' . $disk_name . '?api-version=2021-04-01';
    $result = $client->put($url, [
        'headers' => self::getToken($account_id, true),
        'json' => $body,
    ]);

    return json_decode($result->getBody(), true);
}

public static function deleteManagedDisk($account_id, $subscription_id, $resource_group, $disk_name): void
{
    $client = new Client();
    $url = 'https://management.azure.com/subscriptions/' . $subscription_id . '/resourceGroups/' . $resource_group . '/providers/Microsoft.Compute/disks/' . $disk_name . '?api-version=2021-04-01';
    $client->delete($url, [
        'headers' => self::getToken($account_id, true),
    ]);
}
```

- [ ] **Step 2: Add VM OS disk update helper**

Add:

```php
public static function updateVirtualMachineOsDisk($account_id, $request_url, $location, array $hardware_profile, array $network_profile, array $os_disk, array $os_profile): array
{
    $body = [
        'location' => $location,
        'properties' => [
            'hardwareProfile' => $hardware_profile,
            'storageProfile' => [
                'osDisk' => $os_disk,
            ],
            'osProfile' => $os_profile,
            'networkProfile' => $network_profile,
        ],
    ];

    $client = new Client();
    $url = 'https://management.azure.com' . $request_url . '?api-version=2021-07-01';
    $result = $client->put($url, [
        'headers' => self::getToken($account_id, true),
        'json' => $body,
    ]);

    return json_decode($result->getBody(), true);
}
```

- [ ] **Step 3: Add credential extension and run command helpers**

Add:

```php
public static function updateLinuxVmAccess($server, $username, array $protected_settings): void
{
    $body = [
        'location' => $server->location,
        'properties' => [
            'publisher' => 'Microsoft.OSTCExtensions',
            'type' => 'VMAccessForLinux',
            'typeHandlerVersion' => '1.5',
            'autoUpgradeMinorVersion' => true,
            'settings' => [
                'check_disk' => 'false',
            ],
            'protectedSettings' => array_merge(['username' => $username], $protected_settings),
        ],
    ];

    $client = new Client();
    $url = 'https://management.azure.com' . $server->request_url . '/extensions/enablevmaccess?api-version=2021-07-01';
    $client->put($url, [
        'headers' => self::getToken($server->account_id, true),
        'json' => $body,
    ]);
}

public static function updateWindowsVmAccess($server, $username, $password): void
{
    $body = [
        'location' => $server->location,
        'properties' => [
            'publisher' => 'Microsoft.Compute',
            'type' => 'VMAccessAgent',
            'typeHandlerVersion' => '2.0',
            'autoUpgradeMinorVersion' => true,
            'settings' => [
                'username' => $username,
            ],
            'protectedSettings' => [
                'password' => $password,
            ],
        ],
    ];

    $client = new Client();
    $url = 'https://management.azure.com' . $server->request_url . '/extensions/enablevmaccess?api-version=2021-07-01';
    $client->put($url, [
        'headers' => self::getToken($server->account_id, true),
        'json' => $body,
    ]);
}

public static function runLinuxShellCommand($server, array $commands): array
{
    $body = [
        'commandId' => 'RunShellScript',
        'script' => $commands,
    ];

    $client = new Client();
    $url = 'https://management.azure.com' . $server->request_url . '/runCommand?api-version=2021-07-01';
    $result = $client->post($url, [
        'headers' => self::getToken($server->account_id, true),
        'json' => $body,
    ]);

    return json_decode($result->getBody(), true);
}
```

- [ ] **Step 4: Add NSG and security rule helpers**

Add:

```php
public static function getNetworkSecurityGroup($account_id, $subscription_id, $resource_group, $name): array
{
    $client = new Client();
    $url = 'https://management.azure.com/subscriptions/' . $subscription_id . '/resourceGroups/' . $resource_group . '/providers/Microsoft.Network/networkSecurityGroups/' . $name . '?api-version=2022-01-01';
    $result = $client->get($url, [
        'headers' => self::getToken($account_id, true),
    ]);

    return json_decode($result->getBody(), true);
}

public static function createNetworkSecurityGroup($server, $name): array
{
    $body = [
        'location' => $server->location,
        'properties' => [
            'securityRules' => [],
        ],
    ];

    $client = new Client();
    $url = 'https://management.azure.com/subscriptions/' . $server->at_subscription_id . '/resourceGroups/' . $server->resource_group . '/providers/Microsoft.Network/networkSecurityGroups/' . $name . '?api-version=2022-01-01';
    $result = $client->put($url, [
        'headers' => self::getToken($server->account_id, true),
        'json' => $body,
    ]);

    return json_decode($result->getBody(), true);
}

public static function updateNetworkInterface($server, array $network_details): array
{
    $client = new Client();
    $url = 'https://management.azure.com/subscriptions/' . $server->at_subscription_id . '/resourceGroups/' . $server->resource_group . '/providers/Microsoft.Network/networkInterfaces/' . $server->network_interfaces . '?api-version=2021-03-01';
    $result = $client->put($url, [
        'headers' => self::getToken($server->account_id, true),
        'json' => [
            'location' => $server->location,
            'properties' => $network_details['properties'],
        ],
    ]);

    return json_decode($result->getBody(), true);
}

public static function listSecurityRules($server, $nsg_name): array
{
    $client = new Client();
    $url = 'https://management.azure.com/subscriptions/' . $server->at_subscription_id . '/resourceGroups/' . $server->resource_group . '/providers/Microsoft.Network/networkSecurityGroups/' . $nsg_name . '/securityRules?api-version=2022-01-01';
    $result = $client->get($url, [
        'headers' => self::getToken($server->account_id, true),
    ]);

    return json_decode($result->getBody(), true);
}

public static function saveSecurityRule($server, $nsg_name, $rule_name, array $properties): array
{
    $client = new Client();
    $url = 'https://management.azure.com/subscriptions/' . $server->at_subscription_id . '/resourceGroups/' . $server->resource_group . '/providers/Microsoft.Network/networkSecurityGroups/' . $nsg_name . '/securityRules/' . $rule_name . '?api-version=2022-01-01';
    $result = $client->put($url, [
        'headers' => self::getToken($server->account_id, true),
        'json' => [
            'properties' => $properties,
        ],
    ]);

    return json_decode($result->getBody(), true);
}

public static function deleteSecurityRule($server, $nsg_name, $rule_name): void
{
    $client = new Client();
    $url = 'https://management.azure.com/subscriptions/' . $server->at_subscription_id . '/resourceGroups/' . $server->resource_group . '/providers/Microsoft.Network/networkSecurityGroups/' . $nsg_name . '/securityRules/' . $rule_name . '?api-version=2022-01-01';
    $client->delete($url, [
        'headers' => self::getToken($server->account_id, true),
    ]);
}
```

- [ ] **Step 5: Syntax check and commit**

Run:

```bash
php -l app/controller/AzureApi.php
```

Expected: `No syntax errors detected`.

Commit:

```bash
git add app/controller/AzureApi.php
git commit -m "feat: add azure vm management api helpers"
```

---

### Task 3: Add Reimage Controller Flow with Rollback

**Files:**
- Modify: `app/controller/UserAzureServer.php`
- Modify: `route/app.php`

- [ ] **Step 1: Add route**

Add near Azure server routes in `route/app.php`:

```php
Route::put('/user/server/azure/reimage/:uuid',   'UserAzureServer/reimage');
```

- [ ] **Step 2: Add helper methods to `UserAzureServer`**

Add the shared validation snippets from the top of this plan inside the `UserAzureServer` class.

- [ ] **Step 3: Add OS profile builder**

Add:

```php
private function buildOsProfile(string $vm_name, string $username, string $credential_mode, string $password, int $ssh_key_id): array
{
    $profile = [
        'computerName' => $vm_name,
        'adminUsername' => $username,
    ];

    if ($credential_mode === 'ssh') {
        $ssh_key = SshKey::where('user_id', session('user_id'))->find($ssh_key_id);
        if ($ssh_key === null) {
            throw new \Exception('未找到可用 SSH 密钥');
        }

        $profile['linuxConfiguration'] = [
            'disablePasswordAuthentication' => true,
            'ssh' => [
                'publicKeys' => [
                    [
                        'path' => '/home/' . $username . '/.ssh/authorized_keys',
                        'keyData' => $ssh_key->public_key,
                    ],
                ],
            ],
        ];
    } else {
        $profile['adminPassword'] = $password;
    }

    return $profile;
}
```

- [ ] **Step 4: Add `reimage()` action**

Implement:

```php
public function reimage($uuid)
{
    $server = $this->findOwnedServer($uuid);
    if ($server === null) {
        return json(Tools::msg('0', '重装失败', '虚拟机不存在或无权操作'));
    }

    $image_key = input('image/s');
    $username = input('username/s');
    $password = input('password/s');
    $credential_mode = input('credential_mode/s', 'password');
    $ssh_key_id = (int) input('ssh_key/d');
    $task_uuid = input('task_uuid/s');
    $images = AzureList::images();

    if (!isset($images[$image_key])) {
        return json(Tools::msg('0', '重装失败', '请选择有效镜像'));
    }

    if (Str::contains($image_key, 'Win') && $credential_mode === 'ssh') {
        return json(Tools::msg('0', '重装失败', 'Windows 镜像不支持 SSH 密钥模式'));
    }

    if ($error = $this->validateVmUsername($username)) {
        return json(Tools::msg('0', '重装失败', $error));
    }

    if ($credential_mode !== 'ssh' && ($error = $this->validateVmPassword($password))) {
        return json(Tools::msg('0', '重装失败', $error));
    }

    $vm_details = AzureApi::getVirtualMachine($server->account_id, $server->request_url);
    $original_disk = $vm_details['properties']['storageProfile']['osDisk'];
    $original_disk_name = $original_disk['name'];
    $replacement_disk_name = $server->name . '_os_' . date('YmdHis');
    $task_id = UserTask::create(session('user_id'), '重装虚拟机系统', [
        'vm_name' => $server->name,
        'original_disk' => $original_disk,
        'replacement_disk' => $replacement_disk_name,
        'image' => $image_key,
    ], $task_uuid);

    $replacement_attached = false;

    try {
        UserTask::update($task_id, 1 / 7, '正在分离计算资源');
        AzureApi::virtualMachinesDeallocate($server->account_id, $server->request_url);

        UserTask::update($task_id, 2 / 7, '正在创建替换系统盘');
        $replacement_disk = AzureApi::createManagedDiskFromImage(
            $server->account_id,
            $server->at_subscription_id,
            $server->resource_group,
            $server->location,
            $replacement_disk_name,
            $images[$image_key],
            $server->disk_size,
            $original_disk['managedDisk']['storageAccountType'] ?? 'Standard_LRS'
        );

        UserTask::update($task_id, 3 / 7, '正在替换系统盘');
        $new_os_disk = $original_disk;
        $new_os_disk['name'] = $replacement_disk_name;
        $new_os_disk['managedDisk']['id'] = $replacement_disk['id'];
        $new_os_disk['createOption'] = 'Attach';

        AzureApi::updateVirtualMachineOsDisk(
            $server->account_id,
            $server->request_url,
            $server->location,
            $vm_details['properties']['hardwareProfile'],
            $vm_details['properties']['networkProfile'],
            $new_os_disk,
            $this->buildOsProfile($server->name, $username, $credential_mode, $password, $ssh_key_id)
        );
        $replacement_attached = true;

        UserTask::update($task_id, 4 / 7, '正在启动虚拟机');
        AzureApi::manageVirtualMachine('start', $server->account_id, $server->request_url);

        UserTask::update($task_id, 5 / 7, '正在刷新虚拟机信息');
        $fresh_vm = AzureApi::getVirtualMachine($server->account_id, $server->request_url);
        $instance_details = AzureApi::getAzureVirtualMachineStatus($server->account_id, $server->request_url);
        $server->vm_details = json_encode($fresh_vm);
        $server->instance_details = json_encode($instance_details);
        $server->os_offer = $images[$image_key]['offer'];
        $server->os_sku = $images[$image_key]['sku'];
        $server->status = $instance_details['statuses']['1']['code'] ?? 'null';
        $server->updated_at = time();
        $server->save();

        UserTask::update($task_id, 6 / 7, '正在清理旧系统盘');
        AzureApi::deleteManagedDisk($server->account_id, $server->at_subscription_id, $server->resource_group, $original_disk_name);
    } catch (\Throwable $e) {
        $error = $this->azureErrorMessage($e);
        if ($replacement_attached) {
            try {
                AzureApi::updateVirtualMachineOsDisk(
                    $server->account_id,
                    $server->request_url,
                    $server->location,
                    $vm_details['properties']['hardwareProfile'],
                    $vm_details['properties']['networkProfile'],
                    $original_disk,
                    $vm_details['properties']['osProfile']
                );
                AzureApi::deleteManagedDisk($server->account_id, $server->at_subscription_id, $server->resource_group, $replacement_disk_name);
                UserTask::end($task_id, true, ['msg' => '重装失败，已回滚：' . $error]);
                return json(Tools::msg('0', '重装失败', '重装失败，已回滚：' . $error));
            } catch (\Throwable $rollback) {
                $rollback_error = $this->azureErrorMessage($rollback);
                UserTask::end($task_id, true, ['msg' => '重装失败，回滚也失败：' . $error . ' / ' . $rollback_error]);
                return json(Tools::msg('0', '重装失败', '重装失败，回滚也失败：' . $error . ' / ' . $rollback_error));
            }
        }

        UserTask::end($task_id, true, ['msg' => $error]);
        return json(Tools::msg('0', '重装失败', $error));
    }

    UserTask::end($task_id, false);
    return json(Tools::msg('1', '重装结果', '重装成功'));
}
```

- [ ] **Step 5: Syntax check and commit**

Run:

```bash
php -l app/controller/UserAzureServer.php
php -l route/app.php
```

Expected: `No syntax errors detected`.

Commit:

```bash
git add app/controller/UserAzureServer.php route/app.php
git commit -m "feat: add azure vm reimage workflow"
```

---

### Task 4: Add Credential Reset Controller Flow

**Files:**
- Modify: `app/controller/UserAzureServer.php`
- Modify: `route/app.php`

- [ ] **Step 1: Add route**

Add:

```php
Route::put('/user/server/azure/credential/:uuid', 'UserAzureServer/credential');
```

- [ ] **Step 2: Add SSH hardening command helper**

Add to `UserAzureServer`:

```php
private function disableLinuxSshPasswordCommands(): array
{
    return [
        "sudo mkdir -p /etc/ssh/sshd_config.d",
        "if [ -f /etc/ssh/sshd_config ]; then sudo sed -i 's/^#\\?PasswordAuthentication .*/PasswordAuthentication no/g' /etc/ssh/sshd_config; fi",
        "if [ -f /etc/ssh/sshd_config ]; then sudo sed -i 's/^#\\?PubkeyAuthentication .*/PubkeyAuthentication yes/g' /etc/ssh/sshd_config; fi",
        "echo 'PasswordAuthentication no' | sudo tee /etc/ssh/sshd_config.d/99-azpanel.conf",
        "echo 'PubkeyAuthentication yes' | sudo tee -a /etc/ssh/sshd_config.d/99-azpanel.conf",
        "sudo systemctl restart sshd || sudo systemctl restart ssh || sudo service sshd restart || sudo service ssh restart",
    ];
}
```

- [ ] **Step 3: Add `credential()` action**

Implement:

```php
public function credential($uuid)
{
    $server = $this->findOwnedServer($uuid);
    if ($server === null) {
        return json(Tools::msg('0', '重置失败', '虚拟机不存在或无权操作'));
    }

    $os_type = input('os_type/s');
    $username = input('username/s');
    $password = input('password/s');
    $credential_mode = input('credential_mode/s', 'password');
    $ssh_key_id = (int) input('ssh_key/d');

    if (!in_array($os_type, ['linux', 'windows'], true)) {
        return json(Tools::msg('0', '重置失败', '请选择系统类型'));
    }

    if ($os_type === 'windows' && $credential_mode === 'ssh') {
        return json(Tools::msg('0', '重置失败', 'Windows 不支持 SSH 密钥模式'));
    }

    if ($error = $this->validateVmUsername($username)) {
        return json(Tools::msg('0', '重置失败', $error));
    }

    try {
        if ($os_type === 'linux' && $credential_mode === 'ssh') {
            $ssh_key = SshKey::where('user_id', session('user_id'))->find($ssh_key_id);
            if ($ssh_key === null) {
                return json(Tools::msg('0', '重置失败', '未找到可用 SSH 密钥'));
            }
            AzureApi::updateLinuxVmAccess($server, $username, [
                'ssh_key' => $ssh_key->public_key,
            ]);
            try {
                AzureApi::runLinuxShellCommand($server, $this->disableLinuxSshPasswordCommands());
            } catch (\Throwable $hardening) {
                return json(Tools::msg('0', '重置失败', '密钥已更新，但关闭密码登录失败：' . $this->azureErrorMessage($hardening)));
            }
        } elseif ($os_type === 'linux') {
            if ($error = $this->validateVmPassword($password)) {
                return json(Tools::msg('0', '重置失败', $error));
            }
            AzureApi::updateLinuxVmAccess($server, $username, [
                'password' => $password,
            ]);
        } else {
            if ($error = $this->validateVmPassword($password)) {
                return json(Tools::msg('0', '重置失败', $error));
            }
            AzureApi::updateWindowsVmAccess($server, $username, $password);
        }
    } catch (\Throwable $e) {
        return json(Tools::msg('0', '重置失败', $this->azureErrorMessage($e)));
    }

    return json(Tools::msg('1', '重置结果', '重置成功'));
}
```

- [ ] **Step 4: Syntax check and commit**

Run:

```bash
php -l app/controller/UserAzureServer.php
php -l route/app.php
```

Expected: `No syntax errors detected`.

Commit:

```bash
git add app/controller/UserAzureServer.php route/app.php
git commit -m "feat: add azure vm credential reset"
```

---

### Task 5: Add Firewall Controller Flow

**Files:**
- Modify: `app/controller/UserAzureServer.php`
- Modify: `route/app.php`

- [ ] **Step 1: Add routes**

Add:

```php
Route::get('/user/server/azure/firewall/:uuid',          'UserAzureServer/firewall');
Route::post('/user/server/azure/firewall/:uuid',         'UserAzureServer/createFirewallRule');
Route::put('/user/server/azure/firewall/:uuid/:name',    'UserAzureServer/updateFirewallRule');
Route::delete('/user/server/azure/firewall/:uuid/:name', 'UserAzureServer/deleteFirewallRule');
```

- [ ] **Step 2: Add NSG resolver**

Add:

```php
private function ensureServerNsg(AzureServer $server): array
{
    $network_details = json_decode($server->network_details, true);
    $nsg_id = $network_details['properties']['networkSecurityGroup']['id'] ?? null;

    if ($nsg_id !== null) {
        $parts = explode('/', $nsg_id);
        return [
            'name' => end($parts),
            'id' => $nsg_id,
        ];
    }

    $nsg_name = $server->name . '_security';
    $nsg = AzureApi::createNetworkSecurityGroup($server, $nsg_name);
    $network_details['properties']['networkSecurityGroup'] = [
        'id' => $nsg['id'],
    ];
    $updated = AzureApi::updateNetworkInterface($server, $network_details);
    $server->network_details = json_encode($updated);
    $server->save();

    return [
        'name' => $nsg_name,
        'id' => $nsg['id'],
    ];
}
```

- [ ] **Step 3: Add firewall validation helper**

Add:

```php
private function buildSecurityRuleProperties(AzureServer $server, string $nsg_name, ?string $current_rule_name = null): array
{
    $name = input('name/s');
    if (!preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
        throw new \Exception('规则名称只允许字母、数字、下划线、点和短横线');
    }

    $priority = (int) input('priority/d');
    if ($priority < 100 || $priority > 4096) {
        throw new \Exception('优先级必须在 100 到 4096 之间');
    }

    $rules = AzureApi::listSecurityRules($server, $nsg_name);
    foreach ($rules['value'] ?? [] as $rule) {
        if (($rule['properties']['priority'] ?? null) === $priority && $rule['name'] !== $current_rule_name) {
            throw new \Exception('优先级已被规则 ' . $rule['name'] . ' 使用');
        }
    }

    $direction = input('direction/s');
    $access = input('access/s');
    $protocol = input('protocol/s');
    if (!in_array($direction, ['Inbound', 'Outbound'], true)) {
        throw new \Exception('方向无效');
    }
    if (!in_array($access, ['Allow', 'Deny'], true)) {
        throw new \Exception('动作无效');
    }
    if (!in_array($protocol, ['Tcp', 'Udp', 'Icmp', '*'], true)) {
        throw new \Exception('协议无效');
    }

    return [
        'description' => mb_substr(input('description/s'), 0, 140),
        'protocol' => $protocol,
        'sourcePortRange' => input('source_port/s', '*'),
        'destinationPortRange' => input('destination_port/s', '*'),
        'sourceAddressPrefix' => input('source_address/s', '*'),
        'destinationAddressPrefix' => input('destination_address/s', '*'),
        'access' => $access,
        'priority' => $priority,
        'direction' => $direction,
    ];
}
```

- [ ] **Step 4: Add firewall actions**

Add:

```php
public function firewall($uuid)
{
    $server = $this->findOwnedServer($uuid);
    if ($server === null) {
        return json(Tools::msg('0', '读取失败', '虚拟机不存在或无权操作'));
    }

    try {
        $nsg = $this->ensureServerNsg($server);
        $rules = AzureApi::listSecurityRules($server, $nsg['name']);
    } catch (\Throwable $e) {
        return json(Tools::msg('0', '读取失败', $this->azureErrorMessage($e)));
    }

    return json(['status' => '1', 'nsg' => $nsg, 'rules' => $rules['value'] ?? []]);
}

public function createFirewallRule($uuid)
{
    $server = $this->findOwnedServer($uuid);
    if ($server === null) {
        return json(Tools::msg('0', '创建失败', '虚拟机不存在或无权操作'));
    }

    try {
        $nsg = $this->ensureServerNsg($server);
        $name = input('name/s');
        AzureApi::saveSecurityRule($server, $nsg['name'], $name, $this->buildSecurityRuleProperties($server, $nsg['name']));
    } catch (\Throwable $e) {
        return json(Tools::msg('0', '创建失败', $this->azureErrorMessage($e)));
    }

    return json(Tools::msg('1', '创建结果', '创建成功'));
}

public function updateFirewallRule($uuid, $name)
{
    $server = $this->findOwnedServer($uuid);
    if ($server === null) {
        return json(Tools::msg('0', '更新失败', '虚拟机不存在或无权操作'));
    }

    try {
        $nsg = $this->ensureServerNsg($server);
        AzureApi::saveSecurityRule($server, $nsg['name'], $name, $this->buildSecurityRuleProperties($server, $nsg['name'], $name));
    } catch (\Throwable $e) {
        return json(Tools::msg('0', '更新失败', $this->azureErrorMessage($e)));
    }

    return json(Tools::msg('1', '更新结果', '更新成功'));
}

public function deleteFirewallRule($uuid, $name)
{
    $server = $this->findOwnedServer($uuid);
    if ($server === null) {
        return json(Tools::msg('0', '删除失败', '虚拟机不存在或无权操作'));
    }

    try {
        $nsg = $this->ensureServerNsg($server);
        AzureApi::deleteSecurityRule($server, $nsg['name'], $name);
    } catch (\Throwable $e) {
        return json(Tools::msg('0', '删除失败', $this->azureErrorMessage($e)));
    }

    return json(Tools::msg('1', '删除结果', '删除成功'));
}
```

- [ ] **Step 5: Syntax check and commit**

Run:

```bash
php -l app/controller/UserAzureServer.php
php -l route/app.php
```

Expected: `No syntax errors detected`.

Commit:

```bash
git add app/controller/UserAzureServer.php route/app.php
git commit -m "feat: add azure vm firewall rule endpoints"
```

---

### Task 6: Add VM Detail UI

**Files:**
- Modify: `app/controller/UserAzureServer.php`
- Modify: `app/view/user/azure/server/read.html`

- [ ] **Step 0: Re-read existing VM detail style**

Open `app/view/user/azure/server/read.html` and confirm the new cards use the same MDUI card, title, form, table, and button classes already used by the existing "调整硬盘", "调整规格", and "流量控制规则" cards. Do not create new CSS classes for the feature UI unless an existing layout bug cannot be fixed with current classes.

- [ ] **Step 1: Assign images and ssh keys to detail view**

In `UserAzureServer::read()`, add:

```php
$ssh_key = SshKey::where('user_id', session('user_id'))->find();
View::assign('images', AzureList::images());
View::assign('ssh_key', $ssh_key);
```

- [ ] **Step 2: Add reinstall card to `read.html`**

Add under the existing management cards:

```html
<div class="mdui-col-md-6 mdui-col-sm-12 mdui-m-t-2">
    <div class="mdui-card" style="overflow: visible">
        <div class="mdui-card-content">
            <p style="color: #3F51B5; font-size: 18px">
                <i class="mdui-icon material-icons">restore</i>&nbsp;重装系统
            </p>
            <div class="mdui-m-t-2">
                镜像：<select id="reimage_image" class="mdui-select" mdui-select>
                    {volist name="images" id="image"}
                    <option value="{$key}">{$image.display}</option>
                    {/volist}
                </select>
            </div>
            <div class="mdui-textfield mdui-textfield-floating-label">
                <label class="mdui-textfield-label">用户名</label>
                <input class="mdui-textfield-input" id="reimage_username">
            </div>
            <div class="mdui-m-t-2">
                凭据：<select id="reimage_credential_mode" class="mdui-select" mdui-select>
                    <option value="password">密码</option>
                    <option value="ssh">SSH 密钥</option>
                </select>
            </div>
            <div class="mdui-textfield mdui-textfield-floating-label" id="reimage_password_wrap">
                <label class="mdui-textfield-label">密码</label>
                <input class="mdui-textfield-input" id="reimage_password">
            </div>
            <div class="mdui-m-t-2" id="reimage_ssh_wrap">
                密钥：<select id="reimage_ssh_key" class="mdui-select" mdui-select>
                    {if !empty($ssh_key)}
                    <option value="{$ssh_key->id}">{$ssh_key->name}</option>
                    {/if}
                </select>
            </div>
            <div class="mdui-m-t-2">
                <button onclick="reimage(this)" data-id="{$server->vm_id}" class="mdui-btn mdui-btn-raised mdui-btn-dense mdui-ripple mdui-color-blue-grey">重装</button>
            </div>
        </div>
    </div>
</div>
```

- [ ] **Step 3: Add credential reset card**

Add:

```html
<div class="mdui-col-md-6 mdui-col-sm-12 mdui-m-t-2">
    <div class="mdui-card" style="overflow: visible">
        <div class="mdui-card-content">
            <p style="color: #3F51B5; font-size: 18px">
                <i class="mdui-icon material-icons">vpn_key</i>&nbsp;重置凭据
            </p>
            <div class="mdui-m-t-2">
                系统：<select id="credential_os_type" class="mdui-select" mdui-select>
                    <option value="linux">Linux</option>
                    <option value="windows">Windows</option>
                </select>
            </div>
            <div class="mdui-textfield mdui-textfield-floating-label">
                <label class="mdui-textfield-label">用户名</label>
                <input class="mdui-textfield-input" id="credential_username">
            </div>
            <div class="mdui-m-t-2">
                凭据：<select id="credential_mode" class="mdui-select" mdui-select>
                    <option value="password">密码</option>
                    <option value="ssh">SSH 密钥</option>
                </select>
            </div>
            <div class="mdui-textfield mdui-textfield-floating-label" id="credential_password_wrap">
                <label class="mdui-textfield-label">密码</label>
                <input class="mdui-textfield-input" id="credential_password">
            </div>
            <div class="mdui-m-t-2" id="credential_ssh_wrap">
                密钥：<select id="credential_ssh_key" class="mdui-select" mdui-select>
                    {if !empty($ssh_key)}
                    <option value="{$ssh_key->id}">{$ssh_key->name}</option>
                    {/if}
                </select>
            </div>
            <div class="mdui-m-t-2">
                <button onclick="credential(this)" data-id="{$server->vm_id}" class="mdui-btn mdui-btn-raised mdui-btn-dense mdui-ripple mdui-color-blue-grey">重置</button>
            </div>
        </div>
    </div>
</div>
```

- [ ] **Step 4: Add firewall card and table container**

Add:

```html
<div class="mdui-col-md-12 mdui-col-sm-12 mdui-m-t-2">
    <div class="mdui-card" style="overflow: visible">
        <div class="mdui-card-content">
            <p style="color: #3F51B5; font-size: 18px">
                <i class="mdui-icon material-icons">security</i>&nbsp;防火墙规则
            </p>
            <button onclick="loadFirewall(this)" data-id="{$server->vm_id}" class="mdui-btn mdui-btn-raised mdui-btn-dense mdui-ripple mdui-color-blue-grey">加载规则</button>
            <div class="mdui-row mdui-m-t-2">
                <input type="hidden" id="firewall_editing_name">
                <div class="mdui-col-md-3 mdui-col-sm-12">
                    <div class="mdui-textfield mdui-textfield-floating-label">
                        <label class="mdui-textfield-label">名称</label>
                        <input class="mdui-textfield-input" id="firewall_name">
                    </div>
                </div>
                <div class="mdui-col-md-3 mdui-col-sm-12">
                    方向：<select id="firewall_direction" class="mdui-select" mdui-select>
                        <option value="Inbound">入站</option>
                        <option value="Outbound">出站</option>
                    </select>
                </div>
                <div class="mdui-col-md-2 mdui-col-sm-12">
                    协议：<select id="firewall_protocol" class="mdui-select" mdui-select>
                        <option value="Tcp">TCP</option>
                        <option value="Udp">UDP</option>
                        <option value="Icmp">ICMP</option>
                        <option value="*">任意</option>
                    </select>
                </div>
                <div class="mdui-col-md-2 mdui-col-sm-12">
                    动作：<select id="firewall_access" class="mdui-select" mdui-select>
                        <option value="Allow">允许</option>
                        <option value="Deny">拒绝</option>
                    </select>
                </div>
                <div class="mdui-col-md-2 mdui-col-sm-12">
                    <div class="mdui-textfield mdui-textfield-floating-label">
                        <label class="mdui-textfield-label">优先级</label>
                        <input class="mdui-textfield-input" id="firewall_priority" type="number" min="100" max="4096">
                    </div>
                </div>
                <div class="mdui-col-md-3 mdui-col-sm-12">
                    <div class="mdui-textfield mdui-textfield-floating-label">
                        <label class="mdui-textfield-label">来源地址</label>
                        <input class="mdui-textfield-input" id="firewall_source_address" value="*">
                    </div>
                </div>
                <div class="mdui-col-md-3 mdui-col-sm-12">
                    <div class="mdui-textfield mdui-textfield-floating-label">
                        <label class="mdui-textfield-label">来源端口</label>
                        <input class="mdui-textfield-input" id="firewall_source_port" value="*">
                    </div>
                </div>
                <div class="mdui-col-md-3 mdui-col-sm-12">
                    <div class="mdui-textfield mdui-textfield-floating-label">
                        <label class="mdui-textfield-label">目标地址</label>
                        <input class="mdui-textfield-input" id="firewall_destination_address" value="*">
                    </div>
                </div>
                <div class="mdui-col-md-3 mdui-col-sm-12">
                    <div class="mdui-textfield mdui-textfield-floating-label">
                        <label class="mdui-textfield-label">目标端口</label>
                        <input class="mdui-textfield-input" id="firewall_destination_port" value="*">
                    </div>
                </div>
                <div class="mdui-col-md-12 mdui-col-sm-12">
                    <div class="mdui-textfield mdui-textfield-floating-label">
                        <label class="mdui-textfield-label">描述</label>
                        <input class="mdui-textfield-input" id="firewall_description">
                    </div>
                </div>
                <div class="mdui-col-md-12 mdui-col-sm-12 mdui-m-t-2">
                    <button onclick="saveFirewallRule(this)" data-id="{$server->vm_id}" class="mdui-btn mdui-btn-raised mdui-btn-dense mdui-ripple mdui-color-blue-grey">保存规则</button>
                    <button onclick="resetFirewallForm()" class="mdui-btn mdui-btn-dense mdui-ripple">清空</button>
                </div>
            </div>
            <div class="mdui-table-fluid-fixed mdui-m-t-2">
                <table class="mdui-table">
                    <thead>
                        <tr>
                            <th>名称</th>
                            <th>方向</th>
                            <th>协议</th>
                            <th>动作</th>
                            <th>优先级</th>
                            <th>来源</th>
                            <th>来源端口</th>
                            <th>目标</th>
                            <th>目标端口</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody id="firewall_rules"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>
```

- [ ] **Step 5: Add JavaScript handlers**

Add near existing script functions:

```javascript
function toggleCredentialFields(prefix) {
    let mode = $('#' + prefix + '_credential_mode').length ? $('#' + prefix + '_credential_mode').val() : $('#' + prefix + '_mode').val();
    $('#' + prefix + '_password_wrap').toggle(mode !== 'ssh');
    $('#' + prefix + '_ssh_wrap').toggle(mode === 'ssh');
}

$('#reimage_credential_mode').change(function () { toggleCredentialFields('reimage'); });
$('#credential_mode').change(function () { toggleCredentialFields('credential'); });
$('#credential_os_type').change(function () {
    if ($(this).val() === 'windows') {
        $('#credential_mode').val('password');
        $('#credential_mode').prop('disabled', true);
    } else {
        $('#credential_mode').prop('disabled', false);
    }
    toggleCredentialFields('credential');
});
toggleCredentialFields('reimage');
toggleCredentialFields('credential');

function reimage(that) {
    let id = $(that).data('id');
    mdui.prompt('输入 yes 确认重装系统盘', '重装确认', function (value) {
        if (value !== 'yes') {
            mdui.alert('为避免误操作，请按提示输入 yes', '重装取消');
            return;
        }
        var load = new mdui.alert('<p id=\"hint\">准备中</p><div class=\"mdui-progress\"><div class=\"mdui-progress-determinate\"></div></div>', '进行中');
        uuid = guid();
        $.ajax({
            method: 'PUT',
            url: '/user/server/azure/reimage/' + id,
            data: {
                task_uuid: uuid,
                image: $('#reimage_image').val(),
                username: $('#reimage_username').val(),
                credential_mode: $('#reimage_credential_mode').val(),
                password: $('#reimage_password').val(),
                ssh_key: $('#reimage_ssh_key').val()
            },
            dataType: 'json',
            beforeSend: function () {
                setInterval(getProgress, 1000);
            },
            success: function (data) {
                load.close();
                mdui.alert(data.content, data.title);
                if (data.status == '1') {
                    setTimeout("window.location.reload()", 1500);
                }
            }
        });
    }, function () {}, { confirmText: '确定', cancelText: '取消' });
}

function credential(that) {
    let id = $(that).data('id');
    $.ajax({
        method: 'PUT',
        url: '/user/server/azure/credential/' + id,
        data: {
            os_type: $('#credential_os_type').val(),
            username: $('#credential_username').val(),
            credential_mode: $('#credential_mode').val(),
            password: $('#credential_password').val(),
            ssh_key: $('#credential_ssh_key').val()
        },
        dataType: 'json',
        success: function (data) {
            mdui.alert(data.content, data.title);
        }
    });
}

function loadFirewall(that) {
    let id = $(that).data('id');
    $.ajax({
        method: 'GET',
        url: '/user/server/azure/firewall/' + id,
        dataType: 'json',
        success: function (data) {
            if (data.status == '0') {
                mdui.alert(data.content, data.title);
                return;
            }
            $('#firewall_rules').empty();
            for (var i = 0; i < data.rules.length; i++) {
                var rule = data.rules[i];
                var encoded = encodeURIComponent(JSON.stringify(rule));
                $('#firewall_rules').append('<tr><td>' + rule.name + '</td><td>' + rule.properties.direction + '</td><td>' + rule.properties.protocol + '</td><td>' + rule.properties.access + '</td><td>' + rule.properties.priority + '</td><td>' + rule.properties.sourceAddressPrefix + '</td><td>' + rule.properties.sourcePortRange + '</td><td>' + rule.properties.destinationAddressPrefix + '</td><td>' + rule.properties.destinationPortRange + '</td><td><button class=\"mdui-btn mdui-btn-dense\" onclick=\"editFirewallRule(\\'' + encoded + '\\')\">编辑</button><button class=\"mdui-btn mdui-btn-dense\" onclick=\"deleteFirewallRule(\\'' + id + '\\', \\'' + rule.name + '\\')\">删除</button></td></tr>');
            }
        }
    });
}

function resetFirewallForm() {
    $('#firewall_editing_name').val('');
    $('#firewall_name').val('');
    $('#firewall_direction').val('Inbound');
    $('#firewall_protocol').val('Tcp');
    $('#firewall_access').val('Allow');
    $('#firewall_priority').val('');
    $('#firewall_source_address').val('*');
    $('#firewall_source_port').val('*');
    $('#firewall_destination_address').val('*');
    $('#firewall_destination_port').val('*');
    $('#firewall_description').val('');
}

function editFirewallRule(encodedRule) {
    var rule = JSON.parse(decodeURIComponent(encodedRule));
    $('#firewall_editing_name').val(rule.name);
    $('#firewall_name').val(rule.name);
    $('#firewall_direction').val(rule.properties.direction);
    $('#firewall_protocol').val(rule.properties.protocol);
    $('#firewall_access').val(rule.properties.access);
    $('#firewall_priority').val(rule.properties.priority);
    $('#firewall_source_address').val(rule.properties.sourceAddressPrefix || '*');
    $('#firewall_source_port').val(rule.properties.sourcePortRange || '*');
    $('#firewall_destination_address').val(rule.properties.destinationAddressPrefix || '*');
    $('#firewall_destination_port').val(rule.properties.destinationPortRange || '*');
    $('#firewall_description').val(rule.properties.description || '');
}

function saveFirewallRule(that) {
    let id = $(that).data('id');
    let editing = $('#firewall_editing_name').val();
    let method = editing === '' ? 'POST' : 'PUT';
    let url = '/user/server/azure/firewall/' + id + (editing === '' ? '' : '/' + editing);
    $.ajax({
        method: method,
        url: url,
        data: {
            name: $('#firewall_name').val(),
            direction: $('#firewall_direction').val(),
            protocol: $('#firewall_protocol').val(),
            access: $('#firewall_access').val(),
            priority: $('#firewall_priority').val(),
            source_address: $('#firewall_source_address').val(),
            source_port: $('#firewall_source_port').val(),
            destination_address: $('#firewall_destination_address').val(),
            destination_port: $('#firewall_destination_port').val(),
            description: $('#firewall_description').val()
        },
        dataType: 'json',
        success: function (data) {
            mdui.alert(data.content, data.title);
            if (data.status == '1') {
                resetFirewallForm();
            }
        }
    });
}

function deleteFirewallRule(id, name) {
    mdui.confirm('确认删除规则 ' + name + '？', '删除确认', function () {
        $.ajax({
            method: 'DELETE',
            url: '/user/server/azure/firewall/' + id + '/' + name,
            dataType: 'json',
            success: function (data) {
                mdui.alert(data.content, data.title);
            }
        });
    }, function () {}, { confirmText: '确定', cancelText: '取消' });
}
```

- [ ] **Step 6: Syntax check and commit**

Run:

```bash
php -l app/controller/UserAzureServer.php
```

Expected: `No syntax errors detected`.

Commit:

```bash
git add app/controller/UserAzureServer.php app/view/user/azure/server/read.html
git commit -m "feat: add azure vm management ui"
```

---

### Task 7: Final Verification

**Files:**
- Verify all changed files.

- [ ] **Step 1: PHP syntax verification**

Run:

```bash
find app route -name '*.php' -print0 | xargs -0 -n1 php -l
```

Expected: every file reports `No syntax errors detected`.

- [ ] **Step 2: Autoload verification**

Run:

```bash
composer dump-autoload
```

Expected: Composer completes without missing AWS class errors.

- [ ] **Step 3: AWS removal verification**

Run:

```bash
rg -n "Route::.*aws|/user/aws|/user/server/aws|UserAws|UserAwsServer|AwsApi|AwsList|aws/aws-sdk-php" composer.json route app
```

Expected: no matches.

- [ ] **Step 4: Azure route/UI verification**

Run:

```bash
rg -n "reimage|credential|firewall" route/app.php app/controller/UserAzureServer.php app/controller/AzureApi.php app/view/user/azure/server/read.html
```

Expected: route, controller, API helper, and UI references are all present.

- [ ] **Step 5: Git status verification**

Run:

```bash
git status --short
```

Expected: clean worktree. If `composer dump-autoload` creates local generated files, inspect and commit only project-relevant changes.
