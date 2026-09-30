<?php

declare(strict_types=1);

namespace WHMCS\Module\Gateway\SeixastecMercadoPago;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Module-specific token in the session initialized by WHMCS. */
final class CheckoutCsrf
{
    private const KEY = 'seixastec_mp_checkout_csrf';

    public static function token(array &$session): string
    {
        $uid = (int) ($session['uid'] ?? 0);
        if ($uid <= 0) {
            throw new \RuntimeException('Authenticated session required.');
        }

        $state = $session[self::KEY] ?? null;
        if (!is_array($state) || ($state['uid'] ?? null) !== $uid
            || !is_string($state['token'] ?? null)
            || !preg_match('/\A[0-9a-f]{64}\z/', $state['token'])) {
            $session[self::KEY] = ['uid' => $uid, 'token' => bin2hex(random_bytes(32))];
        }

        // Reuse across tabs and retries; never rotate on a submitted request.
        return $session[self::KEY]['token'];
    }

    public static function valid(array $session, mixed $submitted): bool
    {
        $uid = (int) ($session['uid'] ?? 0);
        $state = $session[self::KEY] ?? null;
        return $uid > 0 && is_array($state) && ($state['uid'] ?? null) === $uid
            && is_string($state['token'] ?? null) && is_string($submitted)
            && preg_match('/\A[0-9a-f]{64}\z/', $state['token']) === 1
            && hash_equals($state['token'], $submitted);
    }
}
