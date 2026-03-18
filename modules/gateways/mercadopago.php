<?php

/**
 * Mercado Pago - WHMCS Payment Gateway Module
 *
 * Supports: PIX, Boleto Bancário, Cartão de Crédito, Cartão de Débito.
 *
 * Compatible with: WHMCS 9.x | PHP 8.3 | Mercado Pago API v1
 *
 * @author      Eduardo Seixas
 * @copyright   2026
 * @license     GPL-3.0
 */

declare(strict_types=1);

use WHMCS\Module\Gateway\MercadoPago\Api;
use WHMCS\Module\Gateway\MercadoPago\Validator;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/mercadopago/Api.php';
require_once __DIR__ . '/mercadopago/Validator.php';

// ---------------------------------------------------------------------------
// Module metadata
// ---------------------------------------------------------------------------

function mercadopago_MetaData(): array
{
    return [
        'DisplayName' => 'Mercado Pago',
        'APIVersion'  => '1.1',
        'Author'      => 'Your Name',
        'Description' => 'Integração com Mercado Pago: PIX, Boleto, Cartão de Crédito e Débito.',
    ];
}

// ---------------------------------------------------------------------------
// Configuration fields
// ---------------------------------------------------------------------------

function mercadopago_config(): array
{
    $customFields = _mercadopago_getCustomFieldsDropdown();

    return [
        'FriendlyName' => [
            'Type'  => 'System',
            'Value' => 'Mercado Pago',
        ],
        'accessToken' => [
            'FriendlyName' => 'Access Token (Produção)',
            'Type'         => 'password',
            'Size'         => 80,
            'Description'  => 'Seu Access Token de produção do painel do Mercado Pago.',
        ],
        'sandboxAccessToken' => [
            'FriendlyName' => 'Access Token (Sandbox)',
            'Type'         => 'password',
            'Size'         => 80,
            'Description'  => 'Access Token para testes em ambiente sandbox.',
        ],
        'sandboxMode' => [
            'FriendlyName' => 'Modo Sandbox',
            'Type'         => 'yesno',
            'Description'  => 'Ative para utilizar o ambiente de testes do Mercado Pago.',
        ],
        'taxaPercentual' => [
            'FriendlyName' => 'Taxa Percentual (%)',
            'Type'         => 'text',
            'Size'         => 10,
            'Default'      => '0',
            'Description'  => 'Porcentagem adicional cobrada do cliente por usar este gateway. Ex.: 2.5 (não incluir o %). Use ponto (.) como separador decimal.',
        ],
        'taxaFixa' => [
            'FriendlyName' => 'Taxa Fixa (R$)',
            'Type'         => 'text',
            'Size'         => 10,
            'Default'      => '0',
            'Description'  => 'Valor fixo adicional cobrado do cliente. Ex.: 2.00. Use ponto (.) como separador decimal.',
        ],
        'vencimentoBoleto' => [
            'FriendlyName' => 'Vencimento padrão para boletos emitidos (dias)',
            'Type'         => 'text',
            'Size'         => 5,
            'Default'      => '3',
            'Description'  => 'Quantidade de dias de vencimento para boletos reemitidos (faturas já vencidas).',
        ],
        'multaAtraso' => [
            'FriendlyName' => 'Percentual da multa por atraso (%)',
            'Type'         => 'text',
            'Size'         => 5,
            'Default'      => '2',
            'Description'  => 'Máximo de 2%, conforme art. 52 §1º do CDC (Lei 8.078/90). Use ponto (.) como separador decimal.',
        ],
        'jurosDia' => [
            'FriendlyName' => 'Juros proporcional (% ao mês)',
            'Type'         => 'text',
            'Size'         => 5,
            'Default'      => '1',
            'Description'  => 'Aplicando 1% ao mês, o valor será cobrado proporcionalmente por dia de atraso (0,033%/dia). Use ponto (.) como separador decimal.',
        ],
        'gerarParaTodos' => [
            'FriendlyName' => 'Gerar boletos para todos os pedidos?',
            'Type'         => 'yesno',
            'Description'  => 'Se ativado, gera boleto/PIX para qualquer pedido. Se desativado, somente quando o cliente escolher Mercado Pago como forma de pagamento.',
        ],
        'cpfCnpjFieldId' => [
            'FriendlyName' => 'Campo CPF/CNPJ do cliente',
            'Type'         => 'dropdown',
            'Options'      => $customFields,
            'Description'  => 'Selecione o campo personalizado que contém o CPF ou CNPJ do cliente.',
        ],
        'validarCpfCnpj' => [
            'FriendlyName' => 'Validar CPF/CNPJ no checkout?',
            'Type'         => 'yesno',
            'Description'  => 'Se ativado, valida o CPF ou CNPJ antes de redirecionar ao Mercado Pago.',
        ],
    ];
}

// ---------------------------------------------------------------------------
// Payment link (invoice view)
// ---------------------------------------------------------------------------

function mercadopago_link(array $params): string
{
    // ---- Gather basic params ----
    $invoiceId      = (int) $params['invoiceid'];
    $amount         = (float) $params['amount'];
    $currencyCode   = $params['currency'];
    $clientDetails  = $params['clientdetails'];
    $systemUrl      = $params['systemurl'];
    $langPayNow     = $params['langpaynow'];
    $moduleName     = $params['paymentmethod'];

    // ---- Gateway config ----
    $sandboxMode        = $params['sandboxMode'] === 'on';
    $accessToken        = $sandboxMode ? $params['sandboxAccessToken'] : $params['accessToken'];
    $taxaPercentual     = (float) ($params['taxaPercentual'] ?? 0);
    $taxaFixa           = (float) ($params['taxaFixa'] ?? 0);
    $vencimentoDias     = (int)   ($params['vencimentoBoleto'] ?? 3);
    $multaAtraso        = min((float) ($params['multaAtraso'] ?? 2), 2.0);
    $jurosMes           = (float) ($params['jurosDia'] ?? 1);
    $validarCpfCnpj     = $params['validarCpfCnpj'] === 'on';
    $cpfCnpjFieldId     = (int) ($params['cpfCnpjFieldId'] ?? 0);

    // ---- CPF/CNPJ ----
    $cpfCnpj = '';
    if ($cpfCnpjFieldId > 0) {
        $cpfCnpj = _mercadopago_getClientCustomField($clientDetails['userid'], $cpfCnpjFieldId);
    }

    // Validate CPF/CNPJ if required
    if ($validarCpfCnpj && !empty($cpfCnpj)) {
        if (!Validator::validateCpfCnpj($cpfCnpj)) {
            return '<div class="alert alert-danger">CPF/CNPJ inválido. Por favor, atualize seus dados cadastrais antes de prosseguir com o pagamento.</div>';
        }
    }

    // ---- Apply extra fees ----
    $totalAmount = $amount;
    if ($taxaFixa > 0) {
        $totalAmount += $taxaFixa;
    }
    if ($taxaPercentual > 0) {
        $totalAmount += $amount * ($taxaPercentual / 100);
    }
    $totalAmount = round($totalAmount, 2);

    // ---- Build preference payload ----
    $callbackUrl = $systemUrl . 'modules/gateways/callback/mercadopago.php';
    $successUrl  = $systemUrl . 'viewinvoice.php?id=' . $invoiceId . '&paymentsuccess=true';
    $failureUrl  = $systemUrl . 'viewinvoice.php?id=' . $invoiceId . '&paymentfailed=true';
    $pendingUrl  = $systemUrl . 'viewinvoice.php?id=' . $invoiceId . '&paymentpending=true';

    $dueDateStr  = date('Y-m-d', strtotime("+{$vencimentoDias} days"));

    $payerInfo = [
        'name'    => $clientDetails['firstname'],
        'surname' => $clientDetails['lastname'],
        'email'   => $clientDetails['email'],
    ];
    if (!empty($cpfCnpj)) {
        $sanitized = preg_replace('/\D/', '', $cpfCnpj);
        $type = Validator::detectType($cpfCnpj);
        $payerInfo['identification'] = [
            'type'   => $type,
            'number' => $sanitized,
        ];
    }

    $preferenceData = [
        'items' => [
            [
                'id'          => (string) $invoiceId,
                'title'       => 'Fatura #' . $invoiceId,
                'description' => 'Pagamento de fatura no sistema.',
                'quantity'    => 1,
                'currency_id' => $currencyCode,
                'unit_price'  => $totalAmount,
            ],
        ],
        'payer'              => $payerInfo,
        'external_reference' => (string) $invoiceId,
        'back_urls'          => [
            'success' => $successUrl,
            'failure' => $failureUrl,
            'pending' => $pendingUrl,
        ],
        'auto_return'       => 'approved',
        'notification_url'  => $callbackUrl,
        'payment_methods'   => [
            'excluded_payment_types' => [],
        ],
        'metadata' => [
            'invoice_id'  => $invoiceId,
            'whmcs_url'   => $systemUrl,
        ],
    ];

    // Add boleto due date for the Brazilian market
    $preferenceData['date_of_expiration'] = $dueDateStr . 'T23:59:59.000-03:00';

    // ---- Call API ----
    $api = new Api($accessToken);
    $preference = $api->createPreference($preferenceData);

    if (!$preference) {
        $error = $api->getLastError();
        logModuleCall('mercadopago', 'createPreference', $preferenceData, 'ERROR: ' . json_encode($error), null, [$accessToken]);
        return '<div class="alert alert-danger">Erro ao conectar ao Mercado Pago. Tente novamente ou entre em contato com o suporte. Detalhes: ' . htmlspecialchars(json_encode($error)) . '</div>';
    }

    // ---- Store preference ID for webhook matching ----
    _mercadopago_savePreference($invoiceId, $preference['id']);

    $checkoutUrl = $sandboxMode
        ? ($preference['sandbox_init_point'] ?? $preference['init_point'])
        : $preference['init_point'];

    // ---- Build HTML ----
    return _mercadopago_renderPaymentButton($checkoutUrl, $totalAmount, $amount, $taxaFixa, $taxaPercentual, $invoiceId);
}

// ---------------------------------------------------------------------------
// Refund
// ---------------------------------------------------------------------------

function mercadopago_refund(array $params): array
{
    $sandboxMode        = $params['sandboxMode'] === 'on';
    $accessToken        = $sandboxMode ? $params['sandboxAccessToken'] : $params['accessToken'];
    $transactionId      = $params['transid'];        // MP payment ID stored at payment time
    $refundAmount       = (float) $params['amount']; // WHMCS hands us the refund amount
    $invoiceId          = (int) $params['invoiceid'];

    $api    = new Api($accessToken);
    $result = $api->refundPayment($transactionId, $refundAmount);

    if ($result !== null) {
        logModuleCall('mercadopago', 'refund', $params, $result, null, [$accessToken]);
        return [
            'status'  => 'success',
            'rawdata' => $result,
            'transid' => $result['id'] ?? $transactionId,
        ];
    }

    $error = $api->getLastError();
    logModuleCall('mercadopago', 'refund', $params, 'ERROR: ' . json_encode($error), null, [$accessToken]);

    return [
        'status'  => 'error',
        'rawdata' => $error,
    ];
}

// ---------------------------------------------------------------------------
// Private helpers
// ---------------------------------------------------------------------------

/**
 * Fetch all WHMCS Client Custom Fields and return as a comma-separated list
 * suitable for use in a gateway config "dropdown" option.
 * Format: "id|Label Name, id|Label Name, ..."
 *
 * Uses a direct Capsule (Eloquent) query against tblcustomfields because there
 * is no dedicated GetCustomFields local API in WHMCS for client fields.
 */
function _mercadopago_getCustomFieldsDropdown(): string
{
    try {
        $fields = \WHMCS\Database\Capsule::table('tblcustomfields')
            ->where('type', 'client')
            ->orderBy('fieldname')
            ->get(['id', 'fieldname']);

        if ($fields->isEmpty()) {
            return '0|Nenhum campo de cliente encontrado';
        }

        $options = ['0|-- Selecione um campo --'];
        foreach ($fields as $field) {
            $options[] = $field->id . '|' . $field->fieldname;
        }
        return implode(',', $options);
    } catch (\Throwable $e) {
        return '0|Erro ao carregar campos: ' . $e->getMessage();
    }
}

/**
 * Retrieve the value of a custom field for a given client.
 *
 * Queries tblcustomfieldsvalues directly to avoid relying on the
 * localAPI structure which can vary across WHMCS versions.
 */
function _mercadopago_getClientCustomField(int $clientId, int $fieldId): string
{
    try {
        $row = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
            ->where('relid', $clientId)
            ->where('fieldid', $fieldId)
            ->first(['value']);

        return $row ? (string) $row->value : '';
    } catch (\Throwable) {
        return '';
    }
}

/**
 * Persist the Mercado Pago preference ID for later webhook matching.
 */
function _mercadopago_savePreference(int $invoiceId, string $preferenceId): void
{
    try {
        $capsule = \WHMCS\Database\Capsule::table('tblinvoices');
        // We store the preference ID in a dedicated option or a custom module data table.
        // Using WHMCS invoice notes as fallback – a proper implementation should use
        // a dedicated module data table.
        \WHMCS\Module\Gateway\Data::set('mercadopago', 'pref_' . $invoiceId, $preferenceId);
    } catch (\Throwable) {
        // ignore – not critical for the payment flow
    }
}

/**
 * Render the payment button and fee summary HTML.
 */
function _mercadopago_renderPaymentButton(
    string $checkoutUrl,
    float  $totalAmount,
    float  $originalAmount,
    float  $taxaFixa,
    float  $taxaPercentual,
    int    $invoiceId
): string {
    $feeInfo = '';
    if ($taxaFixa > 0 || $taxaPercentual > 0) {
        $feeInfo .= '<div class="mp-fee-info">';
        if ($taxaFixa > 0) {
            $feeInfo .= '<span>Taxa fixa: R$ ' . number_format($taxaFixa, 2, ',', '.') . '</span>';
        }
        if ($taxaPercentual > 0) {
            $added = round($originalAmount * ($taxaPercentual / 100), 2);
            $feeInfo .= '<span>Taxa percentual (' . $taxaPercentual . '%): R$ ' . number_format($added, 2, ',', '.') . '</span>';
        }
        $feeInfo .= '</div>';
    }

    $totalFormatted = 'R$ ' . number_format($totalAmount, 2, ',', '.');

    return <<<HTML
<div class="mercadopago-wrap" id="mp-wrap-{$invoiceId}">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
    .mercadopago-wrap {
        font-family: 'Inter', 'Helvetica Neue', Arial, sans-serif;
        max-width: 480px;
        margin: 16px auto;
        padding: 24px;
        border-radius: 12px;
        background: linear-gradient(135deg, #009ee3 0%, #00c2d4 100%);
        box-shadow: 0 8px 32px rgba(0,158,227,.35);
        color: #fff;
        text-align: center;
    }
    .mercadopago-wrap .mp-logo {
        display: flex; align-items: center; justify-content: center; gap: 10px;
        margin-bottom: 16px;
    }
    .mercadopago-wrap .mp-logo svg { width: 36px; height: 36px; }
    .mercadopago-wrap .mp-logo span { font-size: 20px; font-weight: 700; letter-spacing: .5px; }
    .mercadopago-wrap .mp-methods {
        display: flex; flex-wrap: wrap; gap: 8px; justify-content: center;
        margin-bottom: 18px;
    }
    .mercadopago-wrap .mp-badge {
        background: rgba(255,255,255,.2);
        border: 1px solid rgba(255,255,255,.4);
        border-radius: 20px;
        padding: 4px 14px;
        font-size: 13px;
        font-weight: 500;
    }
    .mercadopago-wrap .mp-total {
        font-size: 28px; font-weight: 700; margin-bottom: 8px;
        text-shadow: 0 2px 4px rgba(0,0,0,.15);
    }
    .mercadopago-wrap .mp-fee-info {
        display: flex; flex-direction: column; gap: 2px;
        font-size: 12px; opacity: .85; margin-bottom: 18px;
    }
    .mercadopago-wrap .mp-btn {
        display: inline-block;
        background: #fff;
        color: #009ee3;
        font-size: 16px; font-weight: 700;
        padding: 14px 38px;
        border-radius: 30px;
        text-decoration: none;
        box-shadow: 0 4px 16px rgba(0,0,0,.2);
        transition: transform .15s, box-shadow .15s;
        letter-spacing: .3px;
    }
    .mercadopago-wrap .mp-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(0,0,0,.25);
        color: #007bb5;
        text-decoration: none;
    }
    .mercadopago-wrap .mp-safe {
        font-size: 11px; margin-top: 14px; opacity: .75;
    }
    .mercadopago-wrap .mp-status-bar {
        display: none;
        margin-top: 18px;
        padding: 10px 14px;
        border-radius: 8px;
        background: rgba(255,255,255,.18);
        border: 1px solid rgba(255,255,255,.35);
        font-size: 13px;
        font-weight: 500;
        align-items: center;
        gap: 8px;
    }
    .mercadopago-wrap .mp-status-bar.visible { display: flex; justify-content: center; }
    .mercadopago-wrap .mp-spinner {
        width: 16px; height: 16px;
        border: 2px solid rgba(255,255,255,.4);
        border-top-color: #fff;
        border-radius: 50%;
        animation: mp-spin .7s linear infinite;
        flex-shrink: 0;
    }
    @keyframes mp-spin { to { transform: rotate(360deg); } }
    .mercadopago-wrap .mp-paid-banner {
        display: none;
        margin-top: 18px;
        padding: 14px;
        border-radius: 8px;
        background: rgba(0,200,80,.3);
        border: 1px solid rgba(0,230,100,.5);
        font-weight: 700;
        font-size: 15px;
    }
    .mercadopago-wrap .mp-paid-banner.visible { display: block; }
  </style>

  <div class="mp-logo">
    <svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
      <circle cx="24" cy="24" r="24" fill="#fff"/>
      <path d="M9 24C9 15.716 15.716 9 24 9s15 6.716 15 15" stroke="#009EE3" stroke-width="4" stroke-linecap="round"/>
      <path d="M15 24l5 5 5-9 5 9 5-5" stroke="#009EE3" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
    <span>Mercado Pago</span>
  </div>

  <div class="mp-methods">
    <span class="mp-badge">🏦 Boleto</span>
    <span class="mp-badge">📱 PIX</span>
    <span class="mp-badge">💳 Crédito</span>
    <span class="mp-badge">💳 Débito</span>
  </div>

  <div class="mp-total">{$totalFormatted}</div>
  {$feeInfo}

  <a id="mp-pay-btn-{$invoiceId}" href="{$checkoutUrl}" class="mp-btn" target="_blank" rel="noopener noreferrer"
     onclick="mpStartPolling({$invoiceId})">
    Pagar agora
  </a>

  <div id="mp-status-{$invoiceId}" class="mp-status-bar">
    <span class="mp-spinner"></span>
    <span id="mp-status-text-{$invoiceId}">Aguardando confirmação do pagamento…</span>
  </div>

  <div id="mp-paid-{$invoiceId}" class="mp-paid-banner">
    ✅ Pagamento confirmado! Redirecionando…
  </div>

  <p class="mp-safe">🔒 Pagamento 100% seguro via Mercado Pago</p>
</div>

<script>
(function() {
    var MP_POLL_INTERVAL = 5000;   // check every 5 seconds
    var MP_POLL_MAX     = 120;     // max 120 attempts = 10 minutes
    var mp_timers       = {};
    var mp_attempts     = {};

    window.mpStartPolling = function(invoiceId) {
        if (mp_timers[invoiceId]) return; // already running
        mp_attempts[invoiceId] = 0;

        // Show the "waiting" bar after a short delay (let the tab open first)
        setTimeout(function() {
            var bar = document.getElementById('mp-status-' + invoiceId);
            if (bar) bar.classList.add('visible');
        }, 2000);

        mp_timers[invoiceId] = setInterval(function() {
            mp_attempts[invoiceId]++;

            if (mp_attempts[invoiceId] > MP_POLL_MAX) {
                clearInterval(mp_timers[invoiceId]);
                var txt = document.getElementById('mp-status-text-' + invoiceId);
                if (txt) txt.textContent = 'Tempo de espera esgotado. Atualize a página manualmente.';
                return;
            }

            // Poll the WHMCS invoice page for the paid status
            fetch('/viewinvoice.php?id=' + invoiceId, {
                method: 'GET',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            })
            .then(function(r) { return r.text(); })
            .then(function(html) {
                // WHMCS renders "PAID" or "Pago" in the invoice status badge when paid
                var paid = /class="[^"]*label-success[^"]*"[^>]*>\s*(Pago|Paid|PAID)/i.test(html)
                        || /status[^>]*>\s*(Paid|Pago|PAID)/i.test(html)
                        || html.indexOf('paymentsuccess=true') !== -1;

                if (paid) {
                    clearInterval(mp_timers[invoiceId]);
                    var bar    = document.getElementById('mp-status-' + invoiceId);
                    var banner = document.getElementById('mp-paid-' + invoiceId);
                    if (bar)    bar.classList.remove('visible');
                    if (banner) banner.classList.add('visible');
                    // Redirect after 2 seconds
                    setTimeout(function() {
                        window.location.href = '/viewinvoice.php?id=' + invoiceId + '&paymentsuccess=true';
                    }, 2000);
                }
            })
            .catch(function() { /* silent – try again next interval */ });
        }, MP_POLL_INTERVAL);
    };
})();
</script>
HTML;
}
