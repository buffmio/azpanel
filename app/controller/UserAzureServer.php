<?php

namespace app\controller;

use app\controller\Ali;
use app\controller\AzureApi;
use app\controller\AzureList;
use app\controller\Tools;
use app\model\Azure;
use app\model\AzureServer;
use app\model\AzureServerResize;
use app\model\Config;
use app\model\ControlRule;
use app\model\SshKey;
use app\model\Traffic;
use app\model\User;
use Carbon\Carbon;
use GuzzleHttp\Client;
use think\facade\View;
use think\helper\Str;

class UserAzureServer extends UserBase
{
    public function index()
    {
        $servers = AzureServer::where('user_id', session('user_id'))
            ->order('id', 'desc')
            ->select();

        foreach ($servers as $server) {
            // 刷新服务器状态
            if ($server->status === 'PowerState/starting' || $server->status === 'PowerState/stopping') {
                $vm_status = AzureApi::getAzureVirtualMachineStatus($server->account_id, $server->request_url);
                $server->status = $vm_status['statuses']['1']['code'] ?? 'null';
                $server->save();
            }
        }

        View::assign([
            'servers' => $servers,
            'count' => $servers->count(),
            'sizes' => AzureList::sizes(),
            'locations' => AzureList::locations(),
            'resolv_sync' => Config::obtain('resolv_sync'),
            'ali_whitelist' => Config::obtain('ali_whitelist'),
        ]);
        return View::fetch('../app/view/user/azure/server/index.html');
    }

    public function create()
    {
        $accounts = Azure::where('user_id', session('user_id'))
            ->where('az_sub_status', 'Enabled')
            ->order('id', 'desc')
            ->select();

        $traffic_rules = ControlRule::where('user_id', session('user_id'))
            ->select();

        $ssh_key = SshKey::where('user_id', session('user_id'))->find();

        $designated_id = (int) input('id');
        if ($designated_id !== 0) {
            $designated_account = Azure::where('user_id', session('user_id'))->find($designated_id);
            if ($designated_account === null) {
                return View::fetch('../app/view/user/reject.html');
            }
            View::assign('designated_account', $designated_account);
        }

        $user = User::find(session('user_id'));
        $personalise = json_decode($user->personalise, true);

        View::assign([
            'ssh_key' => $ssh_key,
            'accounts' => $accounts,
            'personalise' => $personalise,
            'traffic_rules' => $traffic_rules,
            'sizes' => AzureList::sizes(),
            'images' => AzureList::images(),
            'disk_sizes' => AzureList::diskSizes(),
            'locations' => AzureList::locations(),
        ]);
        return View::fetch('../app/view/user/azure/server/create.html');
    }

    public function update($uuid)
    {
        $server = AzureServer::where('user_id', session('user_id'))
            ->where('vm_id', $uuid)
            ->find();

        $server->rule = input('traffic_rule/s');
        $server->save();
        return json(Tools::msg('1', '保存结果', '保存成功'));
    }

    public function save()
    {
        $vm_name = input('vm_name/s');
        $vm_remark = input('vm_remark/s');
        $vm_user = input('vm_user/s');
        $vm_passwd = input('vm_passwd/s');
        $vm_script = input('vm_script/s');
        $vm_location = input('vm_location/s');
        $vm_size = input('vm_size/s');
        $vm_image = input('vm_image/s');
        $task_uuid = input('task_uuid/s');
        //$vm_number       = (int) input('vm_number/s');
        $vm_account = (int) input('vm_account/s');
        $vm_disk_size = (int) input('vm_disk_size/s');
        $vm_ssh_key = (int) input('vm_ssh_key/s');
        $vm_traffic_rule = (int) input('vm_traffic_rule/s');
        $create_check = (int) input('create_check/s');
        $create_ipv6 = (bool) input('create_ipv6/s');

        // 创建账户检查
        if ($vm_account === '') {
            return json(Tools::msg('0', '创建失败', '你还没有添加账户'));
        }

        $account = Azure::find($vm_account);
        if ($account->user_id !== (int) session('user_id')) {
            return json(Tools::msg('0', '创建失败', '你不是此账户的持有者'));
        }

        // 虚拟机用户名与密码检查
        $prohibit_user = ['root', 'Admin', 'admin', 'centos', 'debian', 'ubuntu', 'administrator', 'test'];
        if (!preg_match('/^[a-zA-Z0-9]+$/', $vm_user) || in_array($vm_user, $prohibit_user)) {
            return json(Tools::msg('0', '创建失败', '用户名只允许使用大小写字母与数字的组合，且不能使用常见用户名'));
        }

        $uppercase = preg_match('@[A-Z]@', $vm_passwd);
        $lowercase = preg_match('@[a-z]@', $vm_passwd);
        $number = preg_match('@[0-9]@', $vm_passwd);
        // $symbol    = preg_match('@[^\w]@', $vm_passwd);

        if (!$uppercase || !$lowercase || !$number || strlen($vm_passwd) < 12 || strlen($vm_passwd) > 72) {
            return json(Tools::msg('0', '创建失败', '密码不符合要求，请阅读使用说明'));
        }

        if ($vm_remark === '') {
            $vm_remark = $vm_name;
        }

        // 虚拟机名称与备注检查
        $names = explode(',', $vm_name);
        $remarks = explode(',', $vm_remark);

        $vm_number = count($names);
        if (count($names) !== $vm_number || count($remarks) !== $vm_number || count($names) !== count($remarks)) {
            return json(Tools::msg('0', '创建失败', '请检查创建数量、备注和虚拟机名称是否正确分隔'));
        }

        // 虚拟机名称检查
        foreach ($names as $name) {
            if ($name === '') {
                return json(Tools::msg('0', '创建失败', '虚拟机名称不能为空'));
            }

            if (!preg_match('/^[a-zA-Z0-9]+$/', $name)) {
                return json(Tools::msg('0', '创建失败', '虚拟机名称只允许使用大小写字母与数字的组合'));
            }

            if (strlen($name) > 64) {
                return json(Tools::msg('0', '创建失败', 'Linux 虚拟机名称长度不能超过 64 个字符'));
            }

            if (Str::contains($vm_image, 'Win') && strlen($name) > 15 || is_numeric($name)) {
                return json(Tools::msg('0', '创建失败', 'Windows 虚拟机名称长度不能超过 15 个字符，且不能是纯数字'));
            }
        }

        foreach ($remarks as $remark) {
            if ($remark === '') {
                return json(Tools::msg('0', '创建失败', '虚拟机备注不能为空'));
            }
        }

        // 其他项目检查
        $vm_script = $vm_script === '' ? null : base64_encode($vm_script);

        $images = AzureList::images();
        if (Str::contains($vm_image, 'Win') && !Str::contains($images[$vm_image]['sku'], 'smalldisk') && $vm_disk_size < '127') {
            return json(Tools::msg('0', '创建失败', '此 Windows 系统镜像要求硬盘大小不低于 127 GB'));
        }

        // 记录创建参数
        $params = [
            'account' => [
                'id' => $account->id,
                'status' => $account->az_sub_status,
                'type' => $account->az_sub_type,
                'email' => $account->az_email,
                'check' => $create_check === 1 ? true : false,
            ],
            'server' => [
                'name' => $vm_name,
                'mark' => $vm_remark,
                'count' => $vm_number,
                'disk_size' => $vm_disk_size,
                'user' => $vm_user,
                'image' => $vm_image,
                'location' => $vm_location,
                'size' => $vm_size,
                'script' => $vm_script,
                'ipv6' => $create_ipv6,
            ],
        ];

        /* if (session('user_id') !== 1) {
        return json(Tools::msg('0', '创建失败', '维护中'));
        } */

        // 创建http会话
        if (input('socks5_switch') === 'true') {
            $socks5_addr = input('socks5_address/s');
            $socks5_port = input('socks5_port/d');
            $socks5_user = input('socks5_user/s');
            $socks5_passwd = input('socks5_passwd/s');

            $create_params = [];
            $create_params['timeout'] = 5;

            if ($socks5_user !== '' && $socks5_passwd !== '') {
                $create_params['proxy']['socks5'] = "{$socks5_user}:{$socks5_passwd}@{$socks5_addr}:{$socks5_port}";
            } else {
                $create_params['proxy'] = "socks5://{$socks5_addr}:{$socks5_port}";
            }

            $client = new Client($create_params);
        } else {
            $client = new Client();
        }

        // 初始化创建任务
        $progress = 0;
        $steps = ($vm_number * 7) + 6;
        $task_id = UserTask::create(session('user_id'), '创建虚拟机', $params, $task_uuid);

        if ($create_ipv6) {
            $steps += $vm_number; // 每台虚拟机额外增加创建 ipv6 地址的任务
        }

        if ($account->reg_capacity === 0) {
            ++$steps;
            UserTask::update($task_id, (++$progress / $steps), '正在注册 Microsoft.Capacity');
            AzureApi::registerMainAzureProviders($client, $account, 'Microsoft.Capacity');
        }

        if ($account->providers_register === 0) {
            ++$steps;
            UserTask::update($task_id, (++$progress / $steps), '正在注册 Microsoft.Compute 与 Microsoft.Network');
            AzureApi::registerMainAzureProviders($client, $account, 'Microsoft.Compute');
            AzureApi::registerMainAzureProviders($client, $account, 'Microsoft.Network');

            $account->providers_register = 1;
            $account->save();
        }

        UserTask::update($task_id, (++$progress / $steps), '正在检查订阅状态');
        try {
            $sub_info = AzureApi::getAzureSubscription($account->id); // array
        } catch (\Exception $e) {
            return json(Tools::msg('0', '创建失败', $e->getMessage()));
        }
        if ($sub_info['value']['0']['state'] !== 'Enabled') {
            UserTask::end($task_id, true, json_encode(
                ['msg' => 'This subscription is disabled and therefore marked as read only.']
            ), true);
            return json(Tools::msg('0', '创建失败', '订阅状态被设置为 Disabled 或 Warned'));
        }

        UserTask::update($task_id, (++$progress / $steps), '正在检查订阅可用资源列表');
        $limits = AzureApi::getResourceSkusList($client, $account, $vm_location);
        foreach ($limits['value'] as $limit) {
            if ($limit['name'] === $vm_size) {
                if (isset($limit['restrictions']['0']['reasonCode'])) {
                    if ($limit['restrictions']['0']['reasonCode'] === 'NotAvailableForSubscription' && $create_check === 1) {
                        UserTask::end($task_id, true, json_encode(
                            ['msg' => 'This subscription cannot create VMs of this size in this region.']
                        ), true);
                        return json(Tools::msg('0', '创建失败', '此订阅似乎不能在此区域创建此规格虚拟机。如不信任此检测结果，可以在创建页面将 “检查” 设置为 “忽略” 后重试'));
                    }
                }
                if ($limit['capabilities']['4']['value'] === 'V1') {
                    if (Str::contains($images[$vm_image]['sku'], 'gen2') || Str::contains($images[$vm_image]['sku'], 'g2')) {
                        UserTask::end($task_id, true, json_encode(
                            ['msg' => 'The virtual machine model is not compatible with the image.']
                        ), true);
                        return json(Tools::msg('0', '创建失败', '此规格虚拟机不可使用镜像列表中包含 gen2 关键词的选项'));
                    }
                }
                $size_family = $limit['family'];
                $single_size_core = $limit['capabilities']['2']['value'];
            }
        }

        if ($create_check === 1 && ($account->az_sub_type === 'FreeTrial' || $account->az_sub_type === 'Students')) {
            $ip_num = AzureApi::countAzurePublicNetworkIpv4($client, $account, $vm_location);
            $available = 3 - $ip_num;
            if ($vm_number + $ip_num >= 4) {
                UserTask::end($task_id, true, json_encode(
                    ['msg' => 'FreeTrial subscriptions are only allowed up to 3 IPs per region.']
                ), true);
                return json(Tools::msg('0', '创建失败', "试用订阅在每个区域的公网地址数量被限制为不能超过三个，当前区域还有 {$available} 个公网地址配额。如不信任此检测结果，可以在创建页面将 “检查” 设置为 “忽略” 后重试"));
            }
        }

        // 资源组检查
        UserTask::update($task_id, (++$progress / $steps), '正在检查资源组');
        $resource_groups = AzureApi::getAzureResourceGroupsList($account->id, $account->az_sub_id);
        foreach ($resource_groups['value'] as $resource_group) {
            foreach ($names as $name) {
                $resource_group_name = $name . '_group';
                if (Str::lower($resource_group['name']) === Str::lower($resource_group_name)) {
                    UserTask::end($task_id, true, json_encode(
                        ['msg' => 'A resource group with the same name exists: ' . $name]
                    ), true);
                    return json(Tools::msg('0', '创建失败', '存在同名资源组，请修改虚拟机名称 ' . $name));
                }
            }
        }

        // 核心数检查
        UserTask::update($task_id, (++$progress / $steps), '正在检查配额');
        try {
            $sizes = AzureList::sizes();
            $quotas = AzureApi::getQuota($account, $vm_location);
            if (!isset($sizes[$vm_size]['cpu'])) {
                /* foreach ($limits['value'] as $limit)
                {
                if ($limit['name'] == $vm_size) {
                $single_size_core = $limit['capabilities']['2']['value'];
                break;
                }
                } */
                $cores_total = $single_size_core * $vm_number;
            } else {
                $cores_total = $sizes[$vm_size]['cpu'] * $vm_number;
            }

            foreach ($quotas['value'] as $quota) {
                if ($quota['properties']['name']['value'] === 'cores') {
                    $quota_usage = $quota['properties']['currentValue'];
                    $quota_limit = $quota['properties']['limit'];
                    $account->reg_capacity = 1;
                    $account->save();
                }
                if ($quota['properties']['name']['value'] === $size_family) {
                    $size_quota_usage = $quota['properties']['currentValue'];
                    $size_quota_limit = $quota['properties']['limit'];
                }
            }

            if (isset($quota_usage) && $cores_total + $quota_usage > $quota_limit) {
                $available = $quota_limit - $quota_usage;
                UserTask::end($task_id, true, json_encode(
                    ['msg' => "The required number of cpu cores is {$cores_total}, but the subscription only has {$available} quota."]
                ), true);
                return json(Tools::msg('0', '创建失败', "所需 CPU 核心数为 {$cores_total} 个，但订阅仅有 {$available} 个配额"));
            }
            if (isset($size_quota_usage) && $cores_total + $size_quota_usage > $size_quota_limit) {
                $available = $size_quota_limit - $size_quota_usage;
                UserTask::end($task_id, true, json_encode(
                    ['msg' => "The required number of cpu cores is {$cores_total}, but the size only has {$available} quota."]
                ), true);
                return json(Tools::msg('0', '创建失败', "所需 CPU 核心数为 {$cores_total} 个，但此规格仅有 {$available} 个配额"));
            }
        } catch (\Exception $e) {
            // to do
        }

        // return json(Tools::msg('0', '检查结果', '检查完成'));

        foreach ($names as $vm_name) {
            // default value
            $ipv6 = false;
            $security_group_id = '';
            // name settings
            $vm_ipv4_name = $vm_name . '_ipv4';
            $vm_ipv6_name = $vm_name . '_ipv6';
            $security_group_name = $vm_name . '_security';
            $vm_resource_group_name = $vm_name . '_group';
            $vm_virtual_network_name = $vm_name . '_vnet';

            $vm_config = [
                'vm_size' => $vm_size,
                'vm_disk_size' => $vm_disk_size,
                'vm_user' => $vm_user,
                'vm_passwd' => $vm_passwd,
                'vm_script' => $vm_script,
                'vm_ssh_key' => $vm_ssh_key,
            ];

            try {
                // 创建资源组
                sleep(1);
                UserTask::update($task_id, (++$progress / $steps), '创建资源组 ' . $vm_resource_group_name);
                AzureApi::createAzureResourceGroup(
                    $client,
                    $account,
                    $vm_resource_group_name,
                    $vm_location
                );

                // 创建网络安全组，供 Standard SKU IPv4 与可选 IPv6 共同使用
                UserTask::update($task_id, (++$progress / $steps), '在资源组 ' . $vm_resource_group_name . ' 中创建网络安全组');
                sleep(2);
                $security_group_id = AzureApi::createNetworkSecurityGroups(
                    $client,
                    $account,
                    $vm_resource_group_name,
                    $vm_location,
                    $security_group_name
                );

                // 创建公网ipv4地址
                sleep(2);
                UserTask::update($task_id, (++$progress / $steps), '在资源组 ' . $vm_resource_group_name . ' 中创建 ipv4 地址');
                $ipv4 = AzureApi::createAzurePublicNetworkIpv4(
                    $client,
                    $account,
                    $vm_ipv4_name,
                    $vm_resource_group_name,
                    $vm_location
                );

                if ($create_ipv6) {
                    // 创建公网ipv6地址
                    UserTask::update($task_id, (++$progress / $steps), '在资源组 ' . $vm_resource_group_name . ' 中创建 ipv6 地址');
                    sleep(2);
                    $ipv6 = AzureApi::createAzurePublicNetworkIpv6(
                        $client,
                        $account,
                        $vm_ipv6_name,
                        $vm_resource_group_name,
                        $vm_location
                    );
                }

                // 创建虚拟网络
                UserTask::update($task_id, (++$progress / $steps), '在资源组 ' . $vm_resource_group_name . ' 中创建虚拟网络');
                AzureApi::createAzureVirtualNetwork(
                    $client,
                    $account,
                    $vm_virtual_network_name,
                    $vm_resource_group_name,
                    $vm_location,
                    $create_ipv6
                );

                // 创建子网
                sleep(3);
                UserTask::update($task_id, (++$progress / $steps), '在虚拟网络 ' . $vm_virtual_network_name . ' 中创建子网');
                $subnets = AzureApi::createAzureVirtualNetworkSubnets(
                    $client,
                    $account,
                    $vm_virtual_network_name,
                    $vm_resource_group_name,
                    $vm_location,
                    $create_ipv6
                );

                // 创建网络接口
                sleep(6);
                UserTask::update($task_id, (++$progress / $steps), '在资源组 ' . $vm_resource_group_name . ' 中创建网络接口');
                $interfaces = AzureApi::createAzureVirtualNetworkInterfaces(
                    $client,
                    $account,
                    $vm_name,
                    $ipv4,
                    $ipv6,
                    $subnets,
                    $vm_location,
                    $vm_size,
                    $create_ipv6,
                    $security_group_id
                );

                // 创建虚拟机
                sleep(2);
                UserTask::update($task_id, (++$progress / $steps), '在资源组 ' . $vm_resource_group_name . ' 中创建虚拟机');
                $vm_url = AzureApi::createAzureVm(
                    $client,
                    $account,
                    $vm_name,
                    $vm_config,
                    $vm_image,
                    $interfaces,
                    $vm_location
                );
            } catch (\Exception $e) {
                $error = $e->getMessage();
                UserTask::end($task_id, true, $error);
                return json(Tools::msg('0', '创建失败', $error));
            }
        }

        UserTask::update($task_id, (++$progress / $steps), '等待创建完成');

        // 直到最后一个创建的虚拟机运行状态变为 running 再将所创建的虚拟机加入到列表中
        $count = 0;
        do {
            sleep(2);
            ++$count;
            $vm_status = AzureApi::getAzureVirtualMachineStatus($account->id, $vm_url);
            $status = $vm_status['statuses']['1']['code'] ?? 'null';
        } while ($status !== 'PowerState/running' && $count < 120);

        // 加载到虚拟机列表
        AzureApi::getAzureVirtualMachines($account->id);

        // 同步解析
        if ((int) session('user_id') === (int) Config::obtain('ali_whitelist')) {
            if (Config::obtain('sync_immediately_after_creation')) {
                foreach ($names as $vm_name) {
                    $server = AzureServer::where('user_id', session('user_id'))
                        ->where('name', $vm_name)
                        ->order('id', 'desc')
                        ->limit(1)
                        ->find();
                    try {
                        Ali::createOrUpdate($server->name, $server->ip_address);
                    } catch (\Exception $e) {
                        // ...
                    }
                }
            }
        }

        // 将设置的备注应用
        $pointer = 0;
        foreach ($names as $name) {
            $server = AzureServer::where('user_id', session('user_id'))
                ->where('name', $name)
                ->order('id', 'desc')
                ->limit(1)
                ->find();
            $server->user_remark = $remarks[$pointer];
            $server->rule = $vm_traffic_rule;
            $server->save();
            $pointer += 1;
        }

        UserTask::end($task_id, false);
        return json(Tools::msg('1', '创建结果', '创建成功'));
    }

    public function read($id)
    {
        $server = AzureServer::where('user_id', session('user_id'))->find($id);
        if ($server === null) {
            return View::fetch('../app/view/user/reject.html');
        }

        $vm_sizes = AzureList::sizes();
        $disk_sizes = AzureList::diskSizes();
        $disk_tiers = AzureList::diskTiers();
        $traffic_rules = ControlRule::where('user_id', session('user_id'))->select();

        if ($server->disk_details === null) {
            $disk_details = json_encode(AzureApi::getDisks($server));
            $server->disk_details = $disk_details;
            $server->save();
        }

        $vm_details = json_decode($server->vm_details, true);
        $disk_details = $server->disk_details === null ? $disk_details : json_decode($server->disk_details, true);
        $network_details = json_decode($server->network_details, true);
        $instance_details = json_decode($server->instance_details, true);
        $vm_disk_created = strtotime($instance_details['disks']['0']['statuses']['0']['time']);
        $vm_disk_tier = $disk_details['properties']['tier'] ?? 'P4';

        $vm_dialog = json_encode($vm_details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $disk_dialog = json_encode($disk_details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $network_dialog = json_encode($network_details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $instance_dialog = json_encode($instance_details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $ssh_key = SshKey::where('user_id', session('user_id'))->find();

        View::assign('server', $server);
        View::assign('images', AzureList::images());
        View::assign('ssh_key', $ssh_key);
        View::assign('vm_sizes', $vm_sizes);
        View::assign('disk_sizes', $disk_sizes);
        View::assign('disk_tiers', $disk_tiers);
        View::assign('vm_dialog', $vm_dialog);
        View::assign('vm_details', $vm_details);
        View::assign('disk_dialog', $disk_dialog);
        View::assign('vm_disk_tier', $vm_disk_tier);
        View::assign('disk_details', $disk_details);
        View::assign('traffic_rules', $traffic_rules);
        View::assign('network_dialog', $network_dialog);
        View::assign('vm_disk_created', $vm_disk_created);
        View::assign('network_details', $network_details);
        View::assign('instance_dialog', $instance_dialog);
        View::assign('instance_details', $instance_details);
        return View::fetch('../app/view/user/azure/server/read.html');
    }

    public function delete($uuid)
    {
        AzureServer::where('vm_id', $uuid)->delete();

        return json(Tools::msg('1', '移出结果', '移出成功'));
    }

    public function destroy($uuid)
    {
        $server = AzureServer::where('vm_id', $uuid)->find();

        try {
            AzureApi::deleteAzureResourcesGroup($server->account_id, $server->at_subscription_id, $server->resource_group);
        } catch (\Exception $e) {
            return json(Tools::msg('0', '销毁失败', $e->getMessage()));
        }

        $server->delete();

        return json(Tools::msg('1', '销毁结果', '已销毁此虚拟机'));
    }

    public function remark($uuid)
    {
        $remark = input('remark/s');
        if ($remark === '') {
            return json(Tools::msg('0', '修改结果', '备注不能为空'));
        }

        $server = AzureServer::where('vm_id', $uuid)->find();
        $server->user_remark = $remark;
        $server->save();

        return json(Tools::msg('1', '修改结果', '修改成功'));
    }

    public function resize($uuid)
    {
        $new_size = input('new_size/s');
        $server = AzureServer::where('vm_id', $uuid)->find();

        try {
            AzureApi::virtualMachinesResize($new_size, $server->location, $server->account_id, $server->request_url);
        } catch (\Exception $e) {
            return json(Tools::msg('0', '变配失败', $e->getMessage()));
        }

        $log = new AzureServerResize();
        $log->user_id = session('user_id');
        $log->vm_id = $server->vm_id;
        $log->before_size = $server->vm_size;
        $log->after_size = $new_size;
        $log->created_at = time();
        $log->save();

        $server->vm_size = $new_size;
        $server->save();

        return json(Tools::msg('1', '变配结果', '变配成功'));
    }

    public function redisk($uuid)
    {
        $count = 0;
        $new_disk = input('new_disk/s');
        $task_uuid = input('task_uuid/s');
        //$new_tier = input('new_tier/s');
        $server = AzureServer::where('vm_id', $uuid)->find();
        $params = [
            'vm_name' => $server->name,
            'original_size' => $server->disk_size,
            'upgrade_size' => $new_disk,
        ];
        $task_id = UserTask::create(session('user_id'), '更换硬盘大小', $params, $task_uuid);

        try {
            UserTask::update($task_id, (++$count / 4), '正在分离计算资源');
            AzureApi::virtualMachinesDeallocate($server->account_id, $server->request_url);

            do {
                sleep(2);
                $vm_status = AzureApi::getAzureVirtualMachineStatus($server->account_id, $server->request_url);
                $status = $vm_status['statuses']['1']['code'] ?? 'null';
            } while ($status !== 'PowerState/deallocated');

            UserTask::update($task_id, (++$count / 4), '正在启动虚拟机');
            //AzureApi::virtualMachinesRedisk($new_disk, $new_tier, $server);
            AzureApi::virtualMachinesRedisk($new_disk, $server);
            AzureApi::manageVirtualMachine('start', $server->account_id, $server->request_url);

            do {
                sleep(2);
                $vm_status = AzureApi::getAzureVirtualMachineStatus($server->account_id, $server->request_url);
                $status = $vm_status['statuses']['1']['code'] ?? 'null';
            } while ($status !== 'PowerState/running');

            sleep(1);
            UserTask::update($task_id, (++$count / 4), '正在获取新的公网地址');
            $network_details = AzureApi::getAzureNetworkInterfacesDetails($server->account_id, $server->network_interfaces, $server->resource_group, $server->at_subscription_id);

            // update details
            $origin_disk_size = $server->disk_size;
            $server->disk_size = $new_disk;
            $server->disk_details = json_encode(AzureApi::getDisks($server));
            $server->network_details = json_encode($network_details);
            $server->ip_address = $network_details['properties']['ipConfigurations']['0']['properties']['publicIPAddress']['properties']['ipAddress'] ?? 'null';
            $server->save();

            // save change log
            $log = new AzureServerResize();
            $log->user_id = session('user_id');
            $log->vm_id = $server->vm_id;
            $log->before_size = $origin_disk_size;
            $log->after_size = $new_disk;
            $log->created_at = time();
            $log->save();
        } catch (\Exception $e) {
            $error = $e->getResponse()->getBody()->getContents();
            UserTask::end($task_id, true, $error);
            return json(Tools::msg('0', '更换失败', $error));
        }

        UserTask::end($task_id, false);
        return json(Tools::msg('1', '更换结果', '更换成功'));
    }

    public function status($action, $uuid)
    {
        $server = AzureServer::where('vm_id', $uuid)->find();

        try {
            AzureApi::manageVirtualMachine($action, $server->account_id, $server->request_url);
        } catch (\Exception $e) {
            return json(Tools::msg('0', '操作失败', $e->getMessage()));
        }

        sleep(1);
        self::refresh($server->vm_id);

        return json(Tools::msg('1', '执行结果', '成功'));
    }

    public static function refresh($uuid)
    {
        $server = AzureServer::where('vm_id', $uuid)->find();

        try {
            $vm_status = AzureApi::getAzureVirtualMachineStatus($server->account_id, $server->request_url);
        } catch (\Exception $e) {
            return json(Tools::msg('0', '操作失败', $e->getMessage()));
        }

        $server->status = $vm_status['statuses']['1']['code'] ?? 'null';
        $server->save();

        return json(Tools::msg('1', '执行结果', '成功'));
    }

    public function change($uuid)
    {
        $count = 0;
        $steps = 5;
        $task_uuid = input('task_uuid/s');
        $server = AzureServer::where('vm_id', $uuid)->find();
        $params = [
            'vm_name' => $server->name,
            'original_ip' => $server->ip_address,
        ];
        $task_id = UserTask::create(session('user_id'), '更换公网地址', $params, $task_uuid);

        try {
            if (isset($server->ipv6_address)) {
                throw new \Exception('此虚拟机 ipv4 是静态类型地址，不支持更换');
            }

            UserTask::update($task_id, (++$count / $steps), "正在检查 {$server->name} 归属订阅状态");
            $sub_info = AzureApi::getAzureSubscription($server->account_id); // array
            if ($sub_info['value']['0']['state'] !== 'Enabled') {
                UserTask::end($task_id, true, json_encode(
                    ['msg' => 'This subscription is disabled and therefore marked as read only.']
                ), true);
                return json(Tools::msg('0', '更换失败', '订阅状态被设置为 Disabled 或 Warned'));
            }

            UserTask::update($task_id, (++$count / $steps), "正在分离 {$server->name} 计算资源");
            AzureApi::virtualMachinesDeallocate($server->account_id, $server->request_url);

            do {
                sleep(1);
                $vm_status = AzureApi::getAzureVirtualMachineStatus($server->account_id, $server->request_url);
                $status = $vm_status['statuses']['1']['code'] ?? 'null';
            } while ($status !== 'PowerState/deallocated');

            sleep(3);
            UserTask::update($task_id, (++$count / $steps), "正在启动虚拟机 {$server->name}");
            AzureApi::manageVirtualMachine('start', $server->account_id, $server->request_url);

            do {
                sleep(1);
                $vm_status = AzureApi::getAzureVirtualMachineStatus($server->account_id, $server->request_url);
                $status = $vm_status['statuses']['1']['code'] ?? 'null';
            } while ($status !== 'PowerState/running');

            UserTask::update($task_id, (++$count / $steps), "正在获取 {$server->name} 新地址");
            $network_details = AzureApi::getAzureNetworkInterfacesDetails($server->account_id, $server->network_interfaces, $server->resource_group, $server->at_subscription_id);
            $server->network_details = json_encode($network_details);
            $server->ip_address = $network_details['properties']['ipConfigurations']['0']['properties']['publicIPAddress']['properties']['ipAddress'] ?? 'null';
            $server->save();
        } catch (\Exception $e) {
            if ($e->getMessage() !== null) {
                $error = $e->getMessage();
            } else {
                $error = $e->getResponse()->getBody()->getContents();
            }
            UserTask::end(
                $task_id,
                true,
                json_encode(['msg' => $error])
            );
            return json(Tools::msg('0', '更换失败', $error));
        }

        // 同步解析
        if ((int) session('user_id') === (int) Config::obtain('ali_whitelist')) {
            if (Config::obtain('sync_immediately_after_creation')) {
                try {
                    Ali::createOrUpdate($server->name, $server->ip_address);
                } catch (\Exception $e) {
                    // ...
                }
            }
        }

        UserTask::end($task_id, false);
        return json(Tools::msg('1', '更换结果', '更换成功'));
    }

    public function check($ipv4)
    {
        // http://4563.org/?p=368746

        /* try {
        $result = file_get_contents('https://api-v2.50network.com/modules/ipcheck/icmp?ipv4=' . $ipv4);
        $result = json_decode($result, true);
        $cn_net = ($result['firewall-enable'] == true) ? '<p>中国节点 -> <span style="color: green">正常</span>' : '中国节点 -> <span style="color: red">异常</span></p>';
        $intl_net = ($result['firewall-disable'] == true) ? '<p>外国节点 -> <span style="color: green">正常</span>' : '外国节点 -> <span style="color: red">异常</span></p>';

        return json(Tools::msg('1', '检查成功', $cn_net . $intl_net));
        } catch (\Exception $e) {
        return json(Tools::msg('0', '检查失败', $e->getMessage()));
        } */

        try {
            $client = new Client();
            $response = $client->post('https://www.vps234.com/ipcheck/getdata/', [
                'form_params' => [
                    'idName' => 'itemblockid' . Tools::getUnixTimestamp(),
                    'ip' => $ipv4,
                ],
                'verify' => false,
            ]);
            $result = json_decode($response->getBody(), true);
            $r = $result['data']['data'];
            $text = vsprintf(
                '<p>国内ICMP <span style="float: right; color: %s">%s</span></p>
                <p>国内TCP <span style="float: right; color: %s">%s</span></p>
                <div class="mdui-typo"><hr /></div>
                <p>国外ICMP <span style="float: right; color: %s">%s</span></p>
                <p>国外TCP <span style="float: right; color: %s">%s</span></p>',
                [
                    $r['innerICMP'] ? 'green' : 'red',
                    $r['innerICMP'] ? '正常' : '异常',
                    $r['innerTCP'] ? 'green' : 'red',
                    $r['innerTCP'] ? '正常' : '异常',
                    $r['outICMP'] ? 'green' : 'red',
                    $r['outICMP'] ? '正常' : '异常',
                    $r['outTCP'] ? 'green' : 'red',
                    $r['outTCP'] ? '正常' : '异常',
                ]
            );
            return json(Tools::msg('1', '检查结果', $text));
        } catch (\Exception $e) {
            return json(Tools::msg('0', '检查失败', $e->getMessage()));
        }
    }

    public function sync($uuid)
    {
        if ((int) session('user_id') !== (int) Config::obtain('ali_whitelist')) {
            return json(Tools::msg('0', '同步失败', '你不在权限白名单中'));
        }
        $server = AzureServer::where('vm_id', $uuid)->find();

        try {
            Ali::createOrUpdate($server->name, $server->ip_address);
        } catch (\Exception $e) {
            return json(Tools::msg('0', '同步失败', $e->getMessage()));
        }

        return json(Tools::msg('1', '同步结果', '同步成功'));
    }

    public static function processGeneralData($array, $convert = false)
    {
        $text = '';

        if ($convert) {
            foreach ($array as $data) {
                $date = date('d日H时', strtotime($data['timeStamp']));
                $text .= '["' . $date . '", ' . round(round($data['average'] ?? '0', 2) / 1048576) . '],';
            }
        } else {
            foreach ($array as $data) {
                $date = date('d日H时', strtotime($data['timeStamp']));
                $text .= '["' . $date . '", ' . round($data['average'] ?? '0', 2) . '],';
            }
        }

        return $text;
    }

    public static function processNetworkData($array, $total = false)
    {
        $text = '';
        $usage = 0;

        foreach ($array as $data) {
            $date = date('d日H时', strtotime($data['timeStamp']));
            $bytes = round(($data['total'] ?? '0') / 1000000000, 2);
            $text .= '["' . $date . '", ' . $bytes . '],';
            $usage += $bytes;
        }

        return $total === false ? $text : $usage;
    }

    public function chart($id)
    {
        $gap = (int) input('gap');
        $server = AzureServer::find($id);
        if ($server === null || $server->user_id !== (int) session('user_id')) {
            return View::fetch('../app/view/user/reject.html');
        }

        if ($gap === '') {
            $statistics = AzureApi::getVirtualMachineStatistics($server);
        } else {
            $timestamp = strtotime(Carbon::parse("+{$gap} days ago")->toDateTimeString());
            $start_time = date('Y-m-d\T 16:00:00\Z', $timestamp);
            $stop_time = date('Y-m-d\T 16:00:00\Z', $timestamp + 86400);
            $chart_day = date('Y-m-d', $timestamp + 86400);

            $statistics = AzureApi::getVirtualMachineStatistics($server, $start_time, $stop_time);
        }

        //dump($statistics['value']);

        foreach ($statistics['value'] as $key => $value) {
            if ($value['name']['value'] === 'Network In Total') {
                $network_in_total = $statistics['value'][$key]['timeseries']['0']['data'];
            }
            if ($value['name']['value'] === 'Network Out Total') {
                $network_out_total = $statistics['value'][$key]['timeseries']['0']['data'];
            }
            if ($value['name']['value'] === 'Percentage CPU') {
                $percentage_cpu = $statistics['value'][$key]['timeseries']['0']['data'];
            }
            if ($value['name']['value'] === 'CPU Credits Remaining') {
                $cpu_credits = $statistics['value'][$key]['timeseries']['0']['data'];
            }
            if ($value['name']['value'] === 'Available Memory Bytes') {
                $available_memory = $statistics['value'][$key]['timeseries']['0']['data'];
            }
        }

        $traffic_usage = Traffic::where('uuid', $server->vm_id)->order('id', 'desc')->select();
        $chart_day = $chart_day ?? null;

        $total_in_traffic_usage = 0;
        $total_out_traffic_usage = 0;
        foreach ($traffic_usage as $usage) {
            $total_in_traffic_usage += $usage->u;
            $total_out_traffic_usage += $usage->d;
        }

        View::assign([
            'server' => $server,
            'chart_day' => $chart_day,
            'count' => $traffic_usage->count(),
            'traffic_usage' => $traffic_usage,
            'total_in_traffic_usage' => $total_in_traffic_usage,
            'total_out_traffic_usage' => $total_out_traffic_usage,
            'cpu_credits_text' => self::processGeneralData($cpu_credits),
            'percentage_cpu_text' => self::processGeneralData($percentage_cpu),
            'network_in_total_text' => self::processNetworkData($network_in_total),
            'network_out_total_text' => self::processNetworkData($network_out_total),
            'network_in_traffic' => self::processNetworkData($network_in_total, true),
            'network_out_traffic' => self::processNetworkData($network_out_total, true),
            'available_memory_text' => self::processGeneralData($available_memory, true),
        ]);
        return View::fetch('../app/view/user/azure/server/chart.html');
    }

    public function search()
    {
        $user_id = session('user_id');
        $s_name = input('s_name/s');
        $s_mark = input('s_mark/s');
        $s_size = input('s_size/s');
        $s_public = input('s_public/s');
        $s_status = input('s_status/s');
        $s_location = input('s_location/s');

        $where = [];
        $where[] = ['user_id', '=', $user_id];
        ($s_name !== '') && $where[] = ['name', 'like', '%' . $s_name . '%'];
        ($s_mark !== '') && $where[] = ['user_remark', 'like', '%' . $s_mark . '%'];
        ($s_public !== '') && $where[] = ['ip_address', 'like', '%' . $s_public . '%'];
        ($s_size !== 'all') && $where[] = ['vm_size', '=', $s_size];
        ($s_status !== 'all') && $where[] = ['status', '=', $s_status];
        ($s_location !== 'all') && $where[] = ['location', '=', $s_location];

        $data = AzureServer::where($where)
            ->field('vm_id')
            ->select();

        // $sql = Db::getLastSql();

        return json(['result' => $data]);
    }

    public function available()
    {
        $location = input('location/s');
        $vm_account = input('vm_account/s');

        $set = [];
        $client = new Client();
        $account = Azure::where('user_id', session('user_id'))->find($vm_account);
        $limits = AzureApi::getResourceSkusList($client, $account, $location);

        foreach ($limits['value'] as $limit) {
            if ($limit['resourceType'] === 'virtualMachines') {
                // 若虚拟机规格中包含关键字p 则代表是arm64处理器 与默认镜像不兼容 因此需要过滤掉
                if (!isset($limit['restrictions']['0']['reasonCode']) && !Str::contains($limit['name'], 'p')) {
                    $size = [
                        'name' => $limit['name'],
                        'size_name' => $limit['name'] . ' => ' . $limit['capabilities']['2']['value'] . 'C_' . $limit['capabilities']['5']['value'] . 'GB',
                    ];
                    array_push($set, $size);
                }
            }
        }

        return json($set);
    }

    public function price()
    {
        $vm_size = input('vm_size/s');
        $location = input('location/s');
        $vm_sku = str_replace('Standard_', '', $vm_size);

        try {
            $client = new Client();
            $addr = "https://prices.azure.com/api/retail/prices?api-version=2021-10-01-preview";
            $query = "armRegionName eq '{$location}' and SkuName eq '{$vm_sku}' and priceType eq 'Consumption' and serviceName eq 'Virtual Machines' ";
            $url = $addr . '&' . http_build_query(['$filter' => $query]);
            $response = $client->request('GET', $url);
            $json_data = json_decode($response->getBody(), true);

            $prices = [];
            foreach ($json_data['Items'] as $item) {
                $prices[] = $item['retailPrice'];
            }
            return json([
                'prices' => $prices,
            ]);
        } catch (\Exception $e) {
            return null;
        }
    }

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
            try {
                return $e->getResponse()->getBody()->getContents();
            } catch (\Throwable $readEx) {
                return $e->getMessage();
            }
        }

        return $e->getMessage();
    }

    private function buildOsProfile(string $vm_name, string $username, string $credential_mode, ?string $password, int $ssh_key_id): array
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

        try {
            $vm_details = AzureApi::getVirtualMachine($server->account_id, $server->request_url);
        } catch (\Throwable $e) {
            return json(Tools::msg('0', '重装失败', '获取虚拟机详情失败：' . $this->azureErrorMessage($e)));
        }

        // 校验是否跨操作系统类型重装
        $current_os_type = $vm_details['properties']['storageProfile']['osDisk']['osType'] ?? 'Linux';
        $target_is_win = Str::contains($image_key, 'Win') || Str::startsWith($image_key, 'Win');

        if (strtolower($current_os_type) === 'windows' && !$target_is_win) {
            return json(Tools::msg('0', '重装失败', 'Windows 虚拟机只能重装 Windows 镜像'));
        }
        if (strtolower($current_os_type) === 'linux' && $target_is_win) {
            return json(Tools::msg('0', '重装失败', 'Linux 虚拟机只能重装 Linux 镜像'));
        }

        $original_disk = $vm_details['properties']['storageProfile']['osDisk'];
        $original_disk_name = $original_disk['name'];
        $replacement_disk_name = $server->name . '_os_' . date('YmdHis');
        $task_id = UserTask::create(session('user_id'), '重装虚拟机系统', [
            'vm_name' => $server->name,
            'original_disk' => $original_disk,
            'replacement_disk' => $replacement_disk_name,
            'image' => $image_key,
        ], $task_uuid);

        $replacement_created = false;
        $replacement_attached = false;

        try {
            UserTask::update($task_id, 1 / 7, '正在分离计算资源');
            AzureApi::virtualMachinesDeallocate($server->account_id, $server->request_url);

            // 等待关机
            $count = 0;
            do {
                sleep(2);
                $vm_status = AzureApi::getAzureVirtualMachineStatus($server->account_id, $server->request_url);
                $status = $vm_status['statuses']['1']['code'] ?? 'null';
                $count++;
                if ($count >= 60 && $status !== 'PowerState/deallocated') {
                    throw new \Exception('停止虚拟机并去分配计算资源超时');
                }
            } while ($status !== 'PowerState/deallocated');

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
            $replacement_created = true;

            UserTask::update($task_id, 3 / 7, '正在替换系统盘');
            $new_os_disk = $original_disk;
            $new_os_disk['name'] = $replacement_disk_name;
            $new_os_disk['managedDisk']['id'] = $replacement_disk['id'];
            $new_os_disk['createOption'] = 'Attach';

            // 替换系统盘前我们需要确保OS profile能够成功生成
            $os_profile = $this->buildOsProfile($server->name, $username, $credential_mode, $password, $ssh_key_id);

            // 同时将原 osProfile 等覆盖
            AzureApi::updateVirtualMachineOsDisk(
                $server->account_id,
                $server->request_url,
                $server->location,
                $vm_details['properties']['hardwareProfile'],
                $vm_details['properties']['networkProfile'],
                $new_os_disk,
                $os_profile
            );
            $replacement_attached = true;

            UserTask::update($task_id, 4 / 7, '正在启动虚拟机');
            AzureApi::manageVirtualMachine('start', $server->account_id, $server->request_url);

            // 等待开机
            $count = 0;
            do {
                sleep(2);
                $vm_status = AzureApi::getAzureVirtualMachineStatus($server->account_id, $server->request_url);
                $status = $vm_status['statuses']['1']['code'] ?? 'null';
                $count++;
                if ($count >= 60 && $status !== 'PowerState/running') {
                    throw new \Exception('启动虚拟机超时');
                }
            } while ($status !== 'PowerState/running');

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

            try {
                UserTask::update($task_id, 6 / 7, '正在清理旧系统盘');
                AzureApi::deleteManagedDisk($server->account_id, $server->at_subscription_id, $server->resource_group, $original_disk_name);
            } catch (\Throwable $deleteDiskEx) {
                $warnMsg = '重装系统已成功，但清理旧系统盘失败，请手动到 Azure 控制台清理旧系统盘: ' . $original_disk_name;
                UserTask::end($task_id, false, ['msg' => $warnMsg]);
                return json(Tools::msg('1', '重装警告', $warnMsg));
            }
        } catch (\Throwable $e) {
            $error = $this->azureErrorMessage($e);
            if ($replacement_attached) {
                try {
                    // 回滚原磁盘
                    $original_attached = $original_disk;
                    $original_attached['createOption'] = 'Attach';
                    AzureApi::updateVirtualMachineOsDisk(
                        $server->account_id,
                        $server->request_url,
                        $server->location,
                        $vm_details['properties']['hardwareProfile'],
                        $vm_details['properties']['networkProfile'],
                        $original_attached,
                        $vm_details['properties']['osProfile'] ?? []
                    );
                    // 挂接成功后可以尝试启动，若未启动或者需要清理刚才的临时空盘
                    try {
                        AzureApi::manageVirtualMachine('start', $server->account_id, $server->request_url);
                    } catch (\Throwable $startEx) {}

                    AzureApi::deleteManagedDisk($server->account_id, $server->at_subscription_id, $server->resource_group, $replacement_disk_name);
                    UserTask::end($task_id, true, ['msg' => '重装失败，已回滚：' . $error]);
                    return json(Tools::msg('0', '重装失败', '重装失败，已回滚：' . $error));
                } catch (\Throwable $rollback) {
                    $rollback_error = $this->azureErrorMessage($rollback);
                    UserTask::end($task_id, true, ['msg' => '重装失败，回滚也失败：' . $error . ' / ' . $rollback_error]);
                    return json(Tools::msg('0', '重装失败', '重装失败，回滚也失败：' . $error . ' / ' . $rollback_error));
                }
            } elseif ($replacement_created) {
                // 如果只创建了临时替换盘但是挂载阶段失败，在此阶段清除该独立磁盘
                try {
                    AzureApi::deleteManagedDisk($server->account_id, $server->at_subscription_id, $server->resource_group, $replacement_disk_name);
                } catch (\Throwable $cleanEx) {
                    $error .= ' 并清理临时磁盘盘失败：' . $cleanEx->getMessage();
                }
            }

            UserTask::end($task_id, true, ['msg' => $error]);
            return json(Tools::msg('0', '重装失败', $error));
        }

        UserTask::end($task_id, false);
        return json(Tools::msg('1', '重装结果', '重装成功'));
    }

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

    private function buildSecurityRuleProperties(AzureServer $server, string $nsg_name, ?string $current_rule_name = null): array
    {
        $name = input('name/s');
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
            throw new \Exception('规则名称只允许字母、数字、下划线与短横线');
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

    public function firewall($uuid)
    {
        $server = $this->findOwnedServer($uuid);
        if ($server === null) {
            return json(Tools::msg('0', '读取失败', '虚拟机不存在或无权操作'));
        }

        try {
            $nsg = $this->ensureServerNsg($server);
            $rules = AzureApi::listSecurityRules($server, $nsg['name']);
            $nsg_details = AzureApi::getNetworkSecurityGroup($server->account_id, $server->at_subscription_id, $server->resource_group, $nsg['name']);
            $default_rules = $nsg_details['properties']['defaultSecurityRules'] ?? [];
        } catch (\Throwable $e) {
            return json(Tools::msg('0', '读取失败', $this->azureErrorMessage($e)));
        }

        return json([
            'status' => '1',
            'nsg' => $nsg,
            'rules' => $rules['value'] ?? [],
            'default_rules' => $default_rules
        ]);
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
}
