<?php

use app\service\AzureNetworkSecurityRuleService;

function test_common_preset_for_linux_allows_ssh_http_and_https(): void
{
    $rules = AzureNetworkSecurityRuleService::presetRules('common', false);
    $names = array_column($rules, 'name');

    assertContainsValue('allow_ssh', $names, 'Linux 常用端口应开放 SSH');
    assertContainsValue('allow_http', $names, 'Linux 常用端口应开放 HTTP');
    assertContainsValue('allow_https', $names, 'Linux 常用端口应开放 HTTPS');
    assertContainsValue('allow_all_out', $names, '常用端口应允许全部出站');
}

function test_common_preset_for_windows_allows_rdp_http_and_https(): void
{
    $rules = AzureNetworkSecurityRuleService::presetRules('common', true);
    $names = array_column($rules, 'name');

    assertContainsValue('allow_rdp', $names, 'Windows 常用端口应开放 RDP');
    assertContainsValue('allow_http', $names, 'Windows 常用端口应开放 HTTP');
    assertContainsValue('allow_https', $names, 'Windows 常用端口应开放 HTTPS');
    assertContainsValue('allow_all_out', $names, '常用端口应允许全部出站');
}

function test_secure_preset_only_allows_ssh_for_linux(): void
{
    $rules = AzureNetworkSecurityRuleService::presetRules('secure', false);

    assertSameValue(['allow_ssh', 'allow_all_out'], array_column($rules, 'name'), 'Linux 只 SSH/RDP 预设只应开放 SSH 和出站');
}

function test_all_preset_allows_every_inbound_port(): void
{
    $rules = AzureNetworkSecurityRuleService::presetRules('all', false);

    assertSameValue('allow_any_in', $rules[0]['name'], '全放端口首条规则名称错误');
    assertSameValue('*', $rules[0]['properties']['destinationPortRange'], '全放端口应开放全部目标端口');
    assertSameValue('Inbound', $rules[0]['properties']['direction'], '全放端口首条规则应为入站');
}

function test_build_rule_from_input_normalizes_security_rule_body(): void
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

    assertSameValue('web_8080', $rule['name'], '规则名称错误');
    assertSameValue(300, $rule['properties']['priority'], '优先级应转为整数');
    assertSameValue('8080', $rule['properties']['destinationPortRange'], '目标端口错误');
    assertSameValue('web', $rule['properties']['description'], '规则描述错误');
}

function test_build_rule_rejects_invalid_priority(): void
{
    try {
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
    } catch (InvalidArgumentException $e) {
        assertSameValue('优先级必须在 100 到 4096 之间', $e->getMessage(), '优先级错误提示不匹配');
        return;
    }

    throw new RuntimeException('无效优先级应抛出异常');
}
