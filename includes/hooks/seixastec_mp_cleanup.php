<?php
/**
 * Daily cleanup of stale lock files from the Mercado Pago webhook handler.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

add_hook('DailyCronJob', 1, function () {
    $lockDir = sys_get_temp_dir();
    if (!is_dir($lockDir)) {
        return;
    }

    $cutoff = time() - 86400; // 24 hours
    $count  = 0;

    foreach (glob($lockDir . DIRECTORY_SEPARATOR . 'mp_payment_*.lock') ?: [] as $file) {
        if (@filemtime($file) < $cutoff) {
            if (@unlink($file)) {
                $count++;
            }
        }
    }

    if ($count > 0) {
        logActivity("Mercado Pago: cleaned {$count} stale lock files.");
    }
});
