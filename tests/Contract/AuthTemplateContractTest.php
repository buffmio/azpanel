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
        self::assertStringContainsString('data-notice-close', $footer);
        self::assertStringContainsString('type="button"', $footer);
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

    /**
     * @dataProvider registrationAndPasswordResetPages
     *
     * @param list<string> $contracts
     */
    public function testRegistrationAndPasswordResetFormsPreserveFieldsAndEndpoints(
        string $template,
        array $contracts
    ): void {
        $html = file_get_contents(dirname(__DIR__, 2) . '/app/view/auth/' . $template);

        foreach ($contracts as $contract) {
            self::assertStringContainsString($contract, $html);
        }

        self::assertStringContainsString('method="post"', $html);
        self::assertStringNotContainsString('$.ajax', $html);
        self::assertStringNotContainsString('onclick=', strtolower($html));
        self::assertStringNotContainsString('</html>', strtolower($html));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function registrationAndPasswordResetPages(): iterable
    {
        yield 'register' => [
            'register.html',
            [
                'action="/register"',
                'name="email"',
                'name="passwd"',
                'name="repeat_passwd"',
                'name="verify_code"',
                'name="code"',
                'name="hcaptcha_result"',
                '/register/code',
            ],
        ];
        yield 'forget' => [
            'forget.html',
            [
                'action="/forget"',
                'name="email"',
                'name="passwd"',
                'name="repeat_passwd"',
                'name="verify_code"',
                '/forget/code',
            ],
        ];
    }
}
