<?php

declare(strict_types=1);

namespace WHMCS\Module\Gateway\SeixastecMercadoPago\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WHMCS\Module\Gateway\SeixastecMercadoPago\WebhookSignature;

final class WebhookSignatureTest extends TestCase
{
    public function testValidSignature(): void
    {
        $secret = 'test-secret';
        $dataId = '12345';
        $requestId = 'req-1';
        $ts = '1700000000';
        $template = sprintf('id:%s;request-id:%s;ts:%s;', $dataId, $requestId, $ts);
        $v1 = hash_hmac('sha256', $template, $secret);

        $this->assertTrue(WebhookSignature::isValid(
            "ts={$ts},v1={$v1}",
            $requestId,
            $dataId,
            $secret,
            1700000000
        ));
    }

    public function testRejectsWrongSecret(): void
    {
        $this->assertFalse(WebhookSignature::isValid(
            'ts=1700000000,v1=deadbeef',
            'req-1',
            '12345',
            'secret',
            1700000000
        ));
    }

    public function testRejectsReplayOutsideWindow(): void
    {
        $secret = 'test-secret';
        $ts = '1700000000';
        $template = 'id:12345;request-id:req-1;ts:1700000000;';
        $v1 = hash_hmac('sha256', $template, $secret);

        $this->assertFalse(WebhookSignature::isValid(
            "ts={$ts},v1={$v1}",
            'req-1',
            '12345',
            $secret,
            1700000000 + 301
        ));
    }

    public function testRejectsEmptySecret(): void
    {
        $this->assertFalse(WebhookSignature::isValid('ts=1,v1=abc', 'r', '1', '', 1));
    }
}
