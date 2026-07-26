<?php

declare(strict_types=1);

namespace tests\Contract;

use PHPUnit\Framework\TestCase;
use tests\Support\RouteSourceParser;

final class HttpContractTest extends TestCase
{
    public function testRouteSourceMatchesApprovedContract(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/route/app.php');
        self::assertIsString($source);
        self::assertSame(
            require dirname(__DIR__) . '/Fixtures/http-contract.php',
            RouteSourceParser::parse($source)
        );
    }

    public function testCriticalAuthenticationRoutesRemainStable(): void
    {
        $contract = require dirname(__DIR__) . '/Fixtures/http-contract.php';
        $keys = array_map(
            static fn (array $route): string => "{$route['method']} {$route['path']} {$route['handler']}",
            $contract
        );

        self::assertContains('GET / Auth/index', $keys);
        self::assertContains('POST /login Auth/login', $keys);
        self::assertContains('POST /register Auth/publicRegister', $keys);
        self::assertContains('POST /forget Auth/resetPassword', $keys);
        self::assertContains('POST /logout Auth/logout', $keys);
    }
}
