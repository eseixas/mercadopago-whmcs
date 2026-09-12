<?php

declare(strict_types=1);

namespace WHMCS\Module\Gateway\SeixastecMercadoPago;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

final class WebhookSignature
{
    public const MAX_AGE_SECONDS = 300;

    public const MAX_FUTURE_SECONDS = 60;

    /**
     * Valida o header x-signature do Mercado Pago.
     *
     * Template: id:<data.id minúsculo>;request-id:<x-request-id>;ts:<ts>;
     */
    public static function isValid(
        string $signatureHeader,
        string $requestId,
        string $dataId,
        string $secret,
        ?int $now = null
    ): bool {
        if ($signatureHeader === '' || $dataId === '' || $secret === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $segment) {
            $kv = explode('=', trim($segment), 2);
            if (count($kv) === 2) {
                $parts[trim($kv[0])] = trim($kv[1]);
            }
        }

        $ts = $parts['ts'] ?? '';
        $v1 = $parts['v1'] ?? '';

        if ($ts === '' || $v1 === '') {
            return false;
        }

        $now ??= time();
        $tsInt = (int) $ts;
        if ($tsInt > 0 && ($now - $tsInt > self::MAX_AGE_SECONDS || $tsInt - $now > self::MAX_FUTURE_SECONDS)) {
            return false;
        }

        $template = sprintf(
            'id:%s;request-id:%s;ts:%s;',
            strtolower($dataId),
            $requestId,
            $ts
        );

        return hash_equals(hash_hmac('sha256', $template, $secret), $v1);
    }
}
