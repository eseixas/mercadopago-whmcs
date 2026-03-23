<?php

/**
 * Mercado Pago - WHMCS Webhook / IPN Callback Handler
 *
 * URL registrada no painel do Mercado Pago (Webhooks):
 *   https://SEU_WHMCS/modules/gateways/callback/mercadopago.php
 *
 * Compatible with: WHMCS 9.x | PHP 8.3 | Mercado Pago API v1
 *
 * @author      Eduardo Seixas
 * @copyright   2026
 * @license     GPL-3.0
 */

declare(strict_types=1);

// WHMCS bootstrap
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

use WHMCS\Module\Gateway\MercadoPago\Api;

require_once __DIR__ . '/../mercadopago/Api.php';

// ---------------------------------------------------------------------------
// Identify gateway & load configuration
// ---------------------------------------------------------------------------

$gatewayModuleName = 'mercadopago';
$gatewayParams     = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    die('Module not active');
}

$sandboxMode = $gatewayParams['sandboxMode'] === 'on';
$accessToken = $sandboxMode
    ? $gatewayParams['sandboxAccessToken']
    : $gatewayParams['accessToken'];

// ---------------------------------------------------------------------------
// Read the raw notification body
// ---------------------------------------------------------------------------

$body    = file_get_contents('php://input');
$payload = json_decode($body, true);

// Mercado Pago sends a "type" field indicating the event kind.
$topic  = $_GET['topic']  ?? ($payload['type']    ?? '');
$dataId = $_GET['id']     ?? ($payload['data']['id'] ?? '');

// Log the raw incoming webhook for debugging
logModuleCall(
    $gatewayModuleName,
    'webhook_incoming',
    ['topic' => $topic, 'data_id' => $dataId, 'payload' => $payload],
    $body,
    null
);

// ---------------------------------------------------------------------------
// Only process "payment" notifications
// ---------------------------------------------------------------------------

if (!in_array($topic, ['payment', 'merchant_order'], true) && empty($dataId)) {
    http_response_code(200);
    echo 'OK (ignored)';
    exit;
}

// ---------------------------------------------------------------------------
// Retrieve the payment details from Mercado Pago
// ---------------------------------------------------------------------------

$api     = new Api($accessToken);
$payment = null;

if ($topic === 'payment' && !empty($dataId)) {
    $payment = $api->getPayment((string) $dataId);
} elseif ($topic === 'merchant_order' && !empty($dataId)) {
    // Merchant order can contain multiple payments; pick the approved one.
    $order = $api->getMerchantOrder((string) $dataId);
    if (!empty($order['payments'])) {
        foreach ($order['payments'] as $p) {
            if ($p['status'] === 'approved') {
                $payment = $api->getPayment((string) $p['id']);
                break;
            }
        }
    }
} elseif (!empty($dataId)) {
    // Generic topic – try fetching as payment
    $payment = $api->getPayment((string) $dataId);
}

if (!$payment) {
    logModuleCall($gatewayModuleName, 'webhook_payment_fetch_failed', $dataId, $api->getLastError(), null);
    http_response_code(200);
    echo 'Payment not found';
    exit;
}

// ---------------------------------------------------------------------------
// Guard: only process approved payments
// ---------------------------------------------------------------------------

$paymentStatus = $payment['status'] ?? '';

if ($paymentStatus !== 'approved') {
    logModuleCall($gatewayModuleName, 'webhook_status_skip', $payment, "Status '{$paymentStatus}' – ignoring.", null);
    http_response_code(200);
    echo "Status: {$paymentStatus} (no action)";
    exit;
}

// ---------------------------------------------------------------------------
// Map MP payment → WHMCS invoice ID via external_reference
// ---------------------------------------------------------------------------

$externalReference = (string) ($payment['external_reference'] ?? '');
$invoiceId         = (int) $externalReference;

if ($invoiceId <= 0) {
    logModuleCall($gatewayModuleName, 'webhook_invalid_reference', $payment, 'No valid external_reference.', null);
    http_response_code(200);
    echo 'No invoice reference';
    exit;
}

// ---------------------------------------------------------------------------
// Verify the invoice exists in WHMCS
// ---------------------------------------------------------------------------

$invoiceResult = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);

if ($invoiceResult['result'] !== 'success') {
    logModuleCall($gatewayModuleName, 'webhook_invoice_not_found', $invoiceId, $invoiceResult, null);
    http_response_code(200);
    echo 'Invoice not found';
    exit;
}

// ---------------------------------------------------------------------------
// Determine amounts
// ---------------------------------------------------------------------------

$transactionId = (string) $payment['id'];
$amountPaid    = (float) ($payment['transaction_amount'] ?? 0);
$currency      = $payment['currency_id'] ?? '';
$fee           = (float) ($payment['fee_details'][0]['amount'] ?? 0);

// ---------------------------------------------------------------------------
// Guard: Acquire exclusive lock to prevent race conditions
// ---------------------------------------------------------------------------
// Mercado Pago sends multiple webhooks nearly simultaneously for the same
// payment. A simple check-then-insert has a TOCTOU race condition: both
// requests can see "no existing transaction" before either inserts.
// We solve this with an exclusive file lock per transaction ID.

$lockDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'whmcs_mp_locks';
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0700, true);
}

$lockFile = $lockDir . DIRECTORY_SEPARATOR . 'mp_txn_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $transactionId) . '.lock';
$lockHandle = fopen($lockFile, 'c');

if (!$lockHandle || !flock($lockHandle, LOCK_EX)) {
    logModuleCall($gatewayModuleName, 'webhook_lock_failed', $transactionId, 'Could not acquire lock', null);
    http_response_code(200);
    echo 'Lock failed – will retry';
    exit;
}

// ---------------------------------------------------------------------------
// Inside the lock: check for duplicate transaction, then register payment
// ---------------------------------------------------------------------------

try {
    // Re-check for existing transaction INSIDE the lock (critical for atomicity)
    $existingTransaction = \WHMCS\Database\Capsule::table('tblaccounts')
        ->where('transid', $transactionId)
        ->where('gateway', $gatewayModuleName)
        ->first();

    if ($existingTransaction) {
        logModuleCall(
            $gatewayModuleName,
            'webhook_duplicate_skipped',
            [
                'transaction_id' => $transactionId,
                'invoice_id'     => $invoiceId,
            ],
            'Transaction already recorded – skipping duplicate webhook.',
            null
        );
        http_response_code(200);
        echo 'Duplicate – already recorded';
        exit;
    }

    // Also check if the invoice is already paid (belt and suspenders)
    if ($invoiceResult['status'] === 'Paid') {
        logModuleCall(
            $gatewayModuleName,
            'webhook_invoice_already_paid',
            [
                'transaction_id' => $transactionId,
                'invoice_id'     => $invoiceId,
                'invoice_status' => $invoiceResult['status'],
            ],
            'Invoice already marked as Paid – skipping to avoid credit.',
            null
        );
        http_response_code(200);
        echo 'Invoice already paid – skipped';
        exit;
    }

    // -----------------------------------------------------------------------
    // Register the payment in WHMCS
    // -----------------------------------------------------------------------

    $addPaymentResult = localAPI('AddInvoicePayment', [
        'invoiceid'  => $invoiceId,
        'transid'    => $transactionId,
        'gateway'    => $gatewayModuleName,
        'date'       => date('Ymd'),
        'amount'     => $amountPaid,
        'fees'       => $fee,
        'noemail'    => false, // send payment confirmation email
    ]);

    logModuleCall(
        $gatewayModuleName,
        'webhook_add_payment',
        [
            'invoice_id'    => $invoiceId,
            'transaction_id'=> $transactionId,
            'amount'        => $amountPaid,
            'fee'           => $fee,
            'mp_status'     => $paymentStatus,
        ],
        $addPaymentResult,
        null,
        [$accessToken]
    );

    http_response_code(200);
    echo $addPaymentResult['result'] === 'success' ? 'Payment recorded' : 'Already recorded or error';

} finally {
    // Always release the lock
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    @unlink($lockFile); // cleanup lock file
}

exit;
