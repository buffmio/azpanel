<?php

declare(strict_types=1);

namespace tests\Contract;

use PHPUnit\Framework\TestCase;

final class DockerfileContractTest extends TestCase
{
    public function testComposerLockIsCopiedBeforeDependencyInstallation(): void
    {
        $dockerfile = file_get_contents(dirname(__DIR__, 2) . '/Dockerfile');
        self::assertIsString($dockerfile);

        $copyPosition = strpos($dockerfile, 'COPY composer.json composer.lock ./');
        self::assertNotFalse(
            $copyPosition,
            'Dockerfile must copy composer.lock with composer.json before installing dependencies.'
        );

        $installPosition = strpos($dockerfile, 'RUN composer install');
        self::assertNotFalse($installPosition);
        self::assertLessThan($installPosition, $copyPosition);
    }
}
