<?php

/**
 * Mercado Pago - WHMCS Webhook / IPN Callback Handler
 *
 * URL registrada no painel do Mercado Pago (Webhooks):
 *   https://SEU_WHMCS/modules/gateways/callback/mercadopago.php
 *
 * Compatible with: WHMCS 9.x | PHP 8.3 | Mercado Pago API v1
 *
 * @author      Your Name
 * @copyright   2026
 * @license     MIT
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
// Register the payment in WHMCS (idempotent – WHMCS checks for duplicate transids)
// ---------------------------------------------------------------------------

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
exit;
