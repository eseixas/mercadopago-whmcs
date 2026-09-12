<?php

declare(strict_types=1);

namespace WHMCS\Module\Gateway\SeixastecMercadoPago\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WHMCS\Module\Gateway\SeixastecMercadoPago\BrazilAddress;

final class BrazilAddressTest extends TestCase
{
    public function testFederalUnitFromName(): void
    {
        $this->assertSame('SP', BrazilAddress::federalUnit('São Paulo'));
        $this->assertSame('SP', BrazilAddress::federalUnit('sao paulo'));
        $this->assertSame('RJ', BrazilAddress::federalUnit('RJ'));
    }

    public function testStreetNumber(): void
    {
        $this->assertSame('123', BrazilAddress::streetNumber('Rua das Flores 123'));
        $this->assertSame('S/N', BrazilAddress::streetNumber('Rua das Flores'));
    }
}
