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

    public function testLoginFormPreservesFieldsAndEndpoint(): void
    {
        $html = file_get_contents(dirname(__DIR__, 2) . '/app/view/auth/login.html');
        foreach (['name="email"', 'name="password"', 'name="code"', 'name="hcaptcha_result"'] as $field) {
            self::assertStringContainsString($field, $html);
        }
        self::assertStringContainsString('action="/login"', $html);
        self::assertStringContainsString('method="post"', $html);
        self::assertStringContainsString('/static/js/auth/login.js', $html);
        self::assertStringContainsString('<noscript>', $html);
        self::assertStringNotContainsString('$.ajax', $html);
    }
}
