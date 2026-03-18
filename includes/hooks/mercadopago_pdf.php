<?php

/**
 * Mercado Pago - WHMCS Invoice PDF Hook
 *
 * Injeta informações de PIX e Boleto Bancário no PDF da fatura e no e-mail
 * enviado ao cliente. Coloque este arquivo em:
 *   includes/hooks/mercadopago_pdf.php
 *
 * Compatible with: WHMCS 9.x | PHP 8.3
 *
 * @author      Eduardo Seixas
 * @copyright   2026
 * @license     GPL-3.0
 */

declare(strict_types=1);

use WHMCS\Module\Gateway\MercadoPago\Api;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

// ---------------------------------------------------------------------------
// Hook: Inject payment info into the PDF invoice variables
// ---------------------------------------------------------------------------

add_hook('InvoicePdfVars', 1, function (array $vars): array {
    $invoiceId = (int) ($vars['invoiceid'] ?? 0);
    if ($invoiceId <= 0) {
        return $vars;
    }

    // Only inject when the invoice gateway is mercadopago
    $invoice = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
    if ($invoice['result'] !== 'success') {
        return $vars;
    }
    if (strtolower($invoice['paymentmethod']) !== 'mercadopago') {
        return $vars;
    }

    $gatewayParams = getGatewayVariables('mercadopago');
    $sandboxMode   = $gatewayParams['sandboxMode'] === 'on';
    $accessToken   = $sandboxMode
        ? $gatewayParams['sandboxAccessToken']
        : $gatewayParams['accessToken'];

    // Search for a payment by external reference (invoice id)
    require_once __DIR__ . '/../../modules/gateways/mercadopago/Api.php';
    $api    = new Api($accessToken);
    $search = $api->searchPaymentByReference((string) $invoiceId);

    $pixQrCode       = '';
    $pixCopyPaste    = '';
    $boletoUrl       = '';
    $boletoBarcode   = '';

    if (!empty($search['results'])) {
        foreach ($search['results'] as $payment) {
            $type   = $payment['payment_type_id'] ?? '';
            $status = $payment['status'] ?? '';

            if ($type === 'bank_transfer' && in_array($status, ['pending', 'approved'], true)) {
                $pixQrCode    = $payment['point_of_interaction']['transaction_data']['qr_code_base64'] ?? '';
                $pixCopyPaste = $payment['point_of_interaction']['transaction_data']['qr_code']        ?? '';
            }

            if ($type === 'ticket' && in_array($status, ['pending', 'approved'], true)) {
                $boletoUrl   = $payment['transaction_details']['external_resource_url'] ?? '';
                $boletoBarcode = $payment['barcode']['content'] ?? '';
            }

            if (!empty($pixCopyPaste) || !empty($boletoBarcode)) {
                break; // found what we need
            }
        }
    }

    // Inject into $vars so the invoice template can use them
    $vars['mercadopago_pix_qrcode']    = $pixQrCode;      // base64 image
    $vars['mercadopago_pix_copypaste'] = $pixCopyPaste;   // text string
    $vars['mercadopago_boleto_url']    = $boletoUrl;       // full PDF URL
    $vars['mercadopago_boleto_codigo'] = $boletoBarcode;   // barcode

    return $vars;
});

// ---------------------------------------------------------------------------
// Hook: Append payment info to invoice email (HTML body)
// ---------------------------------------------------------------------------

add_hook('EmailPreSend', 1, function (array $vars): ?array {
    // Only act on invoice emails
    if (!str_contains($vars['messagename'] ?? '', 'Invoice')) {
        return null;
    }

    $invoiceId = (int) ($vars['relid'] ?? 0);
    if ($invoiceId <= 0) {
        return null;
    }

    $invoice = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
    if ($invoice['result'] !== 'success' || strtolower($invoice['paymentmethod']) !== 'mercadopago') {
        return null;
    }

    $gatewayParams = getGatewayVariables('mercadopago');
    $sandboxMode   = $gatewayParams['sandboxMode'] === 'on';
    $accessToken   = $sandboxMode
        ? $gatewayParams['sandboxAccessToken']
        : $gatewayParams['accessToken'];

    require_once __DIR__ . '/../../modules/gateways/mercadopago/Api.php';
    $api    = new Api($accessToken);
    $search = $api->searchPaymentByReference((string) $invoiceId);

    $pixCopyPaste  = '';
    $boletoBarcode = '';
    $boletoUrl     = '';

    if (!empty($search['results'])) {
        foreach ($search['results'] as $payment) {
            $type   = $payment['payment_type_id'] ?? '';
            $status = $payment['status'] ?? '';
            if ($type === 'bank_transfer' && in_array($status, ['pending', 'approved'], true)) {
                $pixCopyPaste = $payment['point_of_interaction']['transaction_data']['qr_code'] ?? '';
            }
            if ($type === 'ticket' && in_array($status, ['pending', 'approved'], true)) {
                $boletoUrl    = $payment['transaction_details']['external_resource_url'] ?? '';
                $boletoBarcode = $payment['barcode']['content'] ?? '';
            }
        }
    }

    if (empty($pixCopyPaste) && empty($boletoBarcode)) {
        return null;
    }

    $extra = '<br><hr style="margin:24px 0"><h3 style="color:#009ee3">Informações de Pagamento – Mercado Pago</h3>';

    if (!empty($pixCopyPaste)) {
        $extra .= <<<HTML
<div style="margin-bottom:16px;">
  <strong>📱 PIX – Copia e Cola</strong><br>
  <code style="background:#f4f4f4;padding:8px 12px;display:inline-block;border-radius:6px;word-break:break-all;font-size:13px;">{$pixCopyPaste}</code>
</div>
HTML;
    }

    if (!empty($boletoBarcode)) {
        $extra .= <<<HTML
<div style="margin-bottom:12px;">
  <strong>🏦 Boleto – Linha Digitável</strong><br>
  <code style="background:#f4f4f4;padding:8px 12px;display:inline-block;border-radius:6px;word-break:break-all;font-size:13px;">{$boletoBarcode}</code>
</div>
HTML;
        if (!empty($boletoUrl)) {
            $extra .= '<p><a href="' . htmlspecialchars($boletoUrl) . '" style="color:#009ee3">🔗 Clique aqui para visualizar o boleto completo</a></p>';
        }
    }

    // Append to existing HTML body
    $vars['message'] .= $extra;

    return $vars;
});
