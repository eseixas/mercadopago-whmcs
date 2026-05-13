<?php

declare(strict_types=1);

namespace WHMCS\Module\Gateway\SeixastecMercadoPago\Tests;

use PHPUnit\Framework\TestCase;
use WHMCS\Module\Gateway\SeixastecMercadoPago\Api;
use InvalidArgumentException;

class ApiTest extends TestCase
{
    public function testInstantiationFailsWithEmptyToken(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Access Token é obrigatório.');
        
        new Api('   ');
    }

    public function testIsSandboxDetectsTestTokens(): void
    {
        $sandboxApi1 = new Api('TEST-123456');
        $this->assertTrue($sandboxApi1->isSandbox());

        $sandboxApi2 = new Api('APP_USR-TEST-123456');
        $this->assertTrue($sandboxApi2->isSandbox());

        $prodApi = new Api('APP_USR-123456');
        $this->assertFalse($prodApi->isSandbox());
    }
}
