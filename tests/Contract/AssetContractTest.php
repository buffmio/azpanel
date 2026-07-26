<?php

declare(strict_types=1);

namespace tests\Contract;

use PHPUnit\Framework\TestCase;

final class AssetContractTest extends TestCase
{
    public function testAppStylesheetImportsLayersInStableOrder(): void
    {
        $css = file_get_contents(dirname(__DIR__, 2) . '/public/static/css/app.css');
        self::assertSame(
            "@import url('./app/tokens.css');\n"
            . "@import url('./app/base.css');\n"
            . "@import url('./app/components.css');\n"
            . "@import url('./app/auth.css');\n",
            $css
        );
    }

    public function testRequiredDesignTokensExist(): void
    {
        $css = file_get_contents(dirname(__DIR__, 2) . '/public/static/css/app/tokens.css');
        foreach (['--az-color-primary', '--az-color-danger', '--az-space-2', '--az-radius-md', '--az-shadow-sm', '--az-focus-ring'] as $token) {
            self::assertStringContainsString($token . ':', $css);
        }
    }
}
