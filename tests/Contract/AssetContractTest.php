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

    public function testDesignTokensExposeTheApprovedFoundationFromRoot(): void
    {
        $css = file_get_contents(dirname(__DIR__, 2) . '/public/static/css/app/tokens.css');
        self::assertIsString($css);
        self::assertSame(1, preg_match('/\A:root\s*\{(?<tokens>.*)\}\s*\z/s', $css, $matches));

        $expected = [
            '--az-color-primary' => '#315bd8',
            '--az-space-2' => '0.5rem',
            '--az-radius-md' => '0.625rem',
            '--az-shadow-sm' => '0 1px 2px rgb(16 24 40 / 6%)',
            '--az-font-sans' => 'Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
            '--az-font-size-base' => '1rem',
            '--az-line-height-base' => '1.5',
            '--az-z-modal' => '1000',
            '--az-breakpoint-sm' => '40rem',
            '--az-breakpoint-md' => '48rem',
            '--az-breakpoint-lg' => '64rem',
            '--az-motion-fast' => '120ms',
            '--az-motion-normal' => '200ms',
            '--az-ease-standard' => 'cubic-bezier(0.2, 0, 0, 1)',
        ];

        foreach ($expected as $token => $value) {
            self::assertMatchesRegularExpression(
                '/^\s*' . preg_quote($token, '/') . ':\s*' . preg_quote($value, '/') . ';\s*$/m',
                $matches['tokens'],
                sprintf('Expected %s to remain in the :root design-token contract.', $token)
            );
        }
    }
}
