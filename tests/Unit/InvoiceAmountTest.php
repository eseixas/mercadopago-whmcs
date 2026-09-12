<?php

declare(strict_types=1);

namespace WHMCS\Module\Gateway\SeixastecMercadoPago\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WHMCS\Module\Gateway\SeixastecMercadoPago\InvoiceAmount;

final class InvoiceAmountTest extends TestCase
{
    public function testExpectedWithoutFee(): void
    {
        $this->assertSame(100.50, InvoiceAmount::expected(100.5));
    }

    public function testExpectedWithFee(): void
    {
        $this->assertSame(110.0, InvoiceAmount::expected(100.0, 10.0));
    }

    public function testMatchesWithinTolerance(): void
    {
        $this->assertTrue(InvoiceAmount::matches(100.00, 100.04));
        $this->assertFalse(InvoiceAmount::matches(100.00, 100.10));
        $this->assertFalse(InvoiceAmount::matches(0.01, 150.00));
    }
}
