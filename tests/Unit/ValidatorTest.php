<?php

declare(strict_types=1);

namespace WHMCS\Module\Gateway\SeixastecMercadoPago\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WHMCS\Module\Gateway\SeixastecMercadoPago\Validator;

final class ValidatorTest extends TestCase
{
    public function testSanitizeRemovesNonDigits(): void
    {
        $this->assertSame('12345', Validator::sanitize('123.45-'));
        $this->assertSame('', Validator::sanitize(''));
    }

    public function testDetectType(): void
    {
        $this->assertSame(Validator::TYPE_CPF, Validator::detectType('11122233344'));
        $this->assertSame(Validator::TYPE_CNPJ, Validator::detectType('11222333000144'));
        $this->assertSame(Validator::TYPE_INVALID, Validator::detectType('123'));
    }

    public function testValidateCpf(): void
    {
        $this->assertFalse(Validator::validateCpf('11111111111'));
        $this->assertFalse(Validator::validateCpf('12345678901'));
        $this->assertFalse(Validator::validateCpf('123'));
        $this->assertTrue(Validator::validateCpf('52998224725'));
        $this->assertTrue(Validator::validate('529.982.247-25'));
    }

    public function testValidateCnpj(): void
    {
        $this->assertFalse(Validator::validateCnpj('11111111111111'));
        $this->assertFalse(Validator::validateCnpj('123'));
        $this->assertTrue(Validator::validateCnpj('11222333000181'));
    }

    public function testMask(): void
    {
        $this->assertSame('***.***.***-44', Validator::mask('11122233344'));
        $this->assertSame('XX.XXX.XXX/XXXX-44', Validator::mask('11222333000144'));
        $this->assertSame('*23', Validator::mask('123'));
    }
}
