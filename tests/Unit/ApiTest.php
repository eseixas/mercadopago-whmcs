<?php

declare(strict_types=1);

namespace WHMCS\Module\Gateway\SeixastecMercadoPago\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Gateway\SeixastecMercadoPago\Api;

final class ApiTest extends TestCase
{
    public function testInstantiationFailsWithEmptyToken(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Access Token é obrigatório.');

        new Api('   ');
    }

    public function testIsSandboxDetectsTestTokens(): void
    {
        $this->assertTrue((new Api('TEST-123456'))->isSandbox());
        $this->assertTrue((new Api('APP_USR-TEST-123456'))->isSandbox());
        $this->assertFalse((new Api('APP_USR-123456'))->isSandbox());
    }
}
