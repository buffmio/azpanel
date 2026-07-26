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
