<?php

$tests = [
    __DIR__ . '/unit/AzureNetworkSecurityRuleServiceTest.php',
    __DIR__ . '/unit/ReinstallProfileServiceTest.php',
    __DIR__ . '/unit/AzureChartDateRangeServiceTest.php',
];

$serviceFiles = [
    __DIR__ . '/../app/service/AzureNetworkSecurityRuleService.php',
    __DIR__ . '/../app/service/ReinstallProfileService.php',
    __DIR__ . '/../app/service/AzureChartDateRangeService.php',
];

$failures = 0;

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . '，期望：' . var_export($expected, true) . '，实际：' . var_export($actual, true));
    }
}

function assertContainsValue($needle, array $haystack, string $message): void
{
    if (!in_array($needle, $haystack, true)) {
        throw new RuntimeException($message . '，未找到：' . var_export($needle, true));
    }
}

foreach ($serviceFiles as $serviceFile) {
    if (is_file($serviceFile)) {
        require_once $serviceFile;
    }
}

foreach ($tests as $testFile) {
    require $testFile;
}

foreach (get_defined_functions()['user'] as $function) {
    if (str_starts_with($function, 'test_')) {
        try {
            $function();
            echo "PASS {$function}\n";
        } catch (Throwable $e) {
            ++$failures;
            echo "FAIL {$function}: {$e->getMessage()}\n";
        }
    }
}

if ($failures > 0) {
    exit(1);
}
