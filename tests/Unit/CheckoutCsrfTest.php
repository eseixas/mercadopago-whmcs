<?php

declare(strict_types=1);

namespace WHMCS\Module\Gateway\SeixastecMercadoPago\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WHMCS\Module\Gateway\SeixastecMercadoPago\CheckoutCsrf;

final class CheckoutCsrfTest extends TestCase
{
    public function testSessionBindingAndRetries(): void
    {
        $session = ['uid' => 1];
        $other = ['uid' => 1];
        $token = CheckoutCsrf::token($session);
        self::assertSame($token, CheckoutCsrf::token($session));
        self::assertNotSame($token, CheckoutCsrf::token($other));
        self::assertTrue(CheckoutCsrf::valid($session, $token));
        self::assertTrue(CheckoutCsrf::valid($session, $token));
        self::assertFalse(CheckoutCsrf::valid($other, $token));
        foreach ([null, '', [], 123, 'wrong'] as $bad) {
            self::assertFalse(CheckoutCsrf::valid($session, $bad));
        }
        self::assertFalse(CheckoutCsrf::valid([], $token));
        $session['uid'] = 2;
        self::assertFalse(CheckoutCsrf::valid($session, $token));
        self::assertNotSame($token, CheckoutCsrf::token($session));
    }
}
