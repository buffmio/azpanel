<?php

namespace app\service;

class AzureNetworkSecurityRuleService
{
    public static function networkSecurityGroupNameFromId(?string $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }

        $parts = explode('/', trim($id, '/'));
        $name = end($parts);

        return $name === false || $name === '' ? null : $name;
    }

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
