<?php

declare(strict_types=1);

namespace WHMCS\Module\Gateway\SeixastecMercadoPago;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

final class InvoiceAmount
{
    public const TOLERANCE = 0.05;

    public static function expected(float $invoiceTotal, float $feePercent = 0.0): float
    {
        if ($feePercent > 0) {
            return round($invoiceTotal * (1 + $feePercent / 100), 2);
        }

        return round($invoiceTotal, 2);
    }

    public static function matches(float $paid, float $expected, float $tolerance = self::TOLERANCE): bool
    {
        return abs($paid - $expected) <= $tolerance;
    }
}
