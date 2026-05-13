<?php

declare(strict_types=1);

namespace WHMCS\Module\Gateway\SeixastecMercadoPago\Tests;

use PHPUnit\Framework\TestCase;
use WHMCS\Module\Gateway\SeixastecMercadoPago\Validator;

class ValidatorTest extends TestCase
{
    public function testSanitizeString(): void
    {
        $this->assertEquals('12345', Validator::sanitizeString('123.45-'));
        $this->assertEquals('ABC123', Validator::sanitizeString('A B C 1 2 3'));
        $this->assertEquals('', Validator::sanitizeString(''));
    }

    public function testDetectType(): void
    {
        $this->assertEquals('CPF', Validator::detectType('11122233344'));
        $this->assertEquals('CNPJ', Validator::detectType('11222333000144'));
        $this->assertEquals('UNKNOWN', Validator::detectType('123')); // invalid length
    }

    public function testValidateCPF(): void
    {
        // Real logic usually requires valid math.
        // E.g., we'll test lengths and the identical digits edge case.
        $this->assertFalse(Validator::validateCPF('11111111111'));
        $this->assertFalse(Validator::validateCPF('12345678901')); // mathematically invalid usually
        $this->assertFalse(Validator::validateCPF('123'));
        
        // We assume 52998224725 is a valid fake CPF (mathematically)
        $this->assertTrue(Validator::validateCPF('52998224725')); 
    }

    public function testValidateCNPJ(): void
    {
        $this->assertFalse(Validator::validateCNPJ('11111111111111'));
        $this->assertFalse(Validator::validateCNPJ('123'));
        
        // A mathematically valid fake CNPJ
        $this->assertTrue(Validator::validateCNPJ('11222333000181')); 
    }

    public function testMaskDocument(): void
    {
        $this->assertEquals('111.***.***-44', Validator::maskDocument('11122233344'));
        $this->assertEquals('11.***.***/****-44', Validator::maskDocument('11222333000144'));
        
        // Invalid length documents should not throw errors but return a safe fallback or exact length mask.
        $this->assertEquals('123', Validator::maskDocument('123'));
    }
}
