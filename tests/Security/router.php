<?php

declare(strict_types=1);

// Copied into a disposable WHMCS fixture by run.php. Never use in WHMCS.
session_save_path(__DIR__ . '/sessions');
session_start();
define('WHMCS', true);
require_once __DIR__ . '/modules/gateways/seixastec_mercadopago/CheckoutCsrf.php';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/session') {
    $_SESSION['uid'] = (int) ($_GET['uid'] ?? 101);
    echo json_encode(['token' => \WHMCS\Module\Gateway\SeixastecMercadoPago\CheckoutCsrf::token($_SESSION)]);
} elseif ($path === '/expire') {
    $_SESSION = [];
    echo '{}';
} elseif ($path === '/trace') {
    echo file_exists(__DIR__ . '/calls.json') ? file_get_contents(__DIR__ . '/calls.json') : '[]';
} elseif ($path === '/process') {
    require __DIR__ . '/modules/gateways/seixastec_mercadopago/process.php';
} elseif ($path === '/pay') {
    require __DIR__ . '/modules/gateways/seixastec_mercadopago/pay.php';
} else {
    echo 'ready';
}
