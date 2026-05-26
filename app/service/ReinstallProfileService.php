<?php

namespace app\service;

class ReinstallProfileService
{
    public static function buildDefaults(array $personalise): array
    {
        $user = trim((string) ($personalise['vm_default_identity'] ?? ''));
        $password = trim((string) ($personalise['vm_default_credentials'] ?? ''));

        return [
            'user' => $user !== '' ? $user : 'azpanel',
            'password' => $password !== '' ? $password : 'Azure123456789',
        ];
    }
}
