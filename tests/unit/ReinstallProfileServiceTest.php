<?php

use app\service\ReinstallProfileService;

function test_reinstall_profile_prefers_user_personalise_defaults(): void
{
    $profile = ReinstallProfileService::buildDefaults([
        'vm_default_identity' => 'customuser',
        'vm_default_credentials' => 'CustomPass123',
    ]);

    assertSameValue('customuser', $profile['user'], '重装默认用户名应优先使用个人偏好');
    assertSameValue('CustomPass123', $profile['password'], '重装默认密码应优先使用个人偏好');
}

function test_reinstall_profile_falls_back_when_personalise_is_missing(): void
{
    $profile = ReinstallProfileService::buildDefaults([]);

    assertSameValue('azpanel', $profile['user'], '缺少个人偏好时应回退默认用户名');
    assertSameValue('Azure123456789', $profile['password'], '缺少个人偏好时应回退默认密码');
}
