<?php
/**
 * Mercado Pago - Hooks WHMCS
 *
 * Hooks complementares ao gateway Mercado Pago (SeixasTec).
 *
 * Hooks implementados:
 *
 *   DailyCronJob              -> Cancela Pix/Boleto expirados; sincroniza pagamentos pendentes
 *   InvoicePaid               -> Cancela QR Pix pendente após pagamento aprovado por outro meio
 *   InvoiceCancelled          -> Cancela pagamentos pendentes no MP
 *   InvoiceCreation           -> Validações pré-fatura (CPF, endereço se modo boleto)
 *   ClientAreaPageViewInvoice -> Injeta CSS/JS auxiliar na fatura
 *   AdminInvoicesControlsOutput -> Botão "Sincronizar com Mercado Pago" no admin
 *   AdminAreaHeadOutput       -> Avisos de configuração no admin
 *   ClientAreaPrimarySidebar  -> Aviso de Pix pendente para o cliente
 *
 * @package   SeixasTec\MercadoPago
 * @author    Eduardo Seixas <https://github.com/eseixas>
 * @version   1.0.0
 * @license   GPL-3.0
 */

declare(strict_types=1);

use WHMCS\Database\Capsule;
use WHMCS\Module\Gateway\SeixastecMercadoPago\Api;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

// Garante o autoload manual da Api (alguns ambientes WHMCS não fazem PSR-4)
$_apiPath = __DIR__ . '/../../modules/gateways/seixastec_mercadopago/Api.php';
if (file_exists($_apiPath) && !class_exists(Api::class, false)) {
    require_once $_apiPath;
}
unset($_apiPath);

// =============================================================================
// CONSTANTES INTERNAS
// =============================================================================

const SEIXASTEC_MP_MODULE = 'seixastec_mercadopago';
const SEIXASTEC_MP_HOOK_PRIORITY = 50;

// =============================================================================
// HOOK 1: DailyCronJob
// Limpa Pix/Boletos expirados e sincroniza pagamentos pendentes
// =============================================================================

add_hook('DailyCronJob', SEIXASTEC_MP_HOOK_PRIORITY, function () {
    $gateway = _seixastec_mp_gateway();
    if ($gateway === null) {
        return;
    }

    try {
        $api = _seixastec_mp_api($gateway);
    } catch (\Throwable $e) {
        _seixastec_mp_log('DailyCron ERROR', $e->getMessage());
        return;
    }

    // Busca faturas Unpaid criadas nos últimos 30 dias usando este gateway
    $invoices = Capsule::table('tblinvoices')
        ->where('paymentmethod', SEIXASTEC_MP_MODULE)
        ->where('status', 'Unpaid')
        ->where('date', '>=', date('Y-m-d', strtotime('-30 days')))
        ->select('id', 'userid', 'total', 'duedate', 'date')
        ->get();

    $stats = ['checked' => 0, 'updated' => 0, 'expired' => 0];

    foreach ($invoices as $invoice) {
        $stats['checked']++;

        $search = $api->searchPaymentsByExternalReference((string) $invoice->id, 10);
        if (!$search || empty($search['results'])) {
            continue;
        }

        foreach ($search['results'] as $payment) {
            $status    = (string) ($payment['status'] ?? '');
            $paymentId = (string) ($payment['id'] ?? '');

            if ($paymentId === '') {
                continue;
            }

            // Pagamento aprovado detectado - aplica
            if ($status === 'approved') {
                $exists = Capsule::table('tblaccounts')
                    ->where('invoiceid', $invoice->id)
                    ->where('transid', $paymentId)
                    ->exists();

                if (!$exists) {
                    $amount = (float) ($payment['transaction_amount'] ?? $invoice->total);
                    $fee    = (float) ($payment['fee_details'][0]['amount'] ?? 0);

                    addInvoicePayment($invoice->id, $paymentId, $amount, $fee, SEIXASTEC_MP_MODULE);
                    logTransaction(SEIXASTEC_MP_MODULE, $payment, 'Sincronizado via DailyCron');
                    $stats['updated']++;
                }
                break;
            }

            // Pix/Boleto expirado - cancela no MP
            if (in_array($status, ['pending', 'in_process'], true)) {
                $expDate = $payment['date_of_expiration'] ?? null;
                if ($expDate && strtotime($expDate) < time()) {
                    $api->cancelPayment($paymentId);
                    _seixastec_mp_log('DailyCron', "Pagamento expirado cancelado: {$paymentId} (fatura {$invoice->id})");
                    $stats['expired']++;
                }
            }
        }
    }

    _seixastec_mp_log('DailyCron SUMMARY', $stats);
});

// =============================================================================
// HOOK 2: InvoicePaid
// Quando uma fatura é paga por outro meio, cancela Pix/Boleto pendente no MP
// =============================================================================

add_hook('InvoicePaid', SEIXASTEC_MP_HOOK_PRIORITY, function (array $vars) {
    $invoiceId = (int) ($vars['invoiceid'] ?? 0);
    if ($invoiceId <= 0) {
        return;
    }

    $gateway = _seixastec_mp_gateway();
    if ($gateway === null) {
        return;
    }

    try {
        $api = _seixastec_mp_api($gateway);
    } catch (\Throwable $e) {
        return;
    }

    $search = $api->searchPaymentsByExternalReference((string) $invoiceId, 10);
    if (!$search || empty($search['results'])) {
        return;
    }

    foreach ($search['results'] as $payment) {
        $status    = (string) ($payment['status'] ?? '');
        $paymentId = (string) ($payment['id'] ?? '');

        if ($paymentId !== '' && in_array($status, ['pending', 'in_process'], true)) {
            $api->cancelPayment($paymentId);
            _seixastec_mp_log('InvoicePaid', "Pagamento pendente cancelado no MP: {$paymentId} (fatura {$invoiceId})");
        }
    }
});

// =============================================================================
// HOOK 3: InvoiceCancelled
// Quando fatura é cancelada no WHMCS, cancela pagamentos pendentes no MP
// =============================================================================

add_hook('InvoiceCancelled', SEIXASTEC_MP_HOOK_PRIORITY, function (array $vars) {
    $invoiceId = (int) ($vars['invoiceid'] ?? 0);
    if ($invoiceId <= 0) {
        return;
    }

    $gateway = _seixastec_mp_gateway();
    if ($gateway === null) {
        return;
    }

    try {
        $api = _seixastec_mp_api($gateway);
    } catch (\Throwable $e) {
        return;
    }

    $search = $api->searchPaymentsByExternalReference((string) $invoiceId, 10);
    if (!$search || empty($search['results'])) {
        return;
    }

    foreach ($search['results'] as $payment) {
        $status    = (string) ($payment['status'] ?? '');
        $paymentId = (string) ($payment['id'] ?? '');

        if ($paymentId !== '' && in_array($status, ['pending', 'in_process', 'authorized'], true)) {
            $api->cancelPayment($paymentId);
            _seixastec_mp_log('InvoiceCancelled', "Cancelado no MP: {$paymentId} (fatura {$invoiceId})");
        }
    }
});

// =============================================================================
// HOOK 4: ClientAreaPageViewInvoice
// Injeta CSS e mostra status amigável de Pix pendente
// =============================================================================

add_hook('ClientAreaPageViewInvoice', SEIXASTEC_MP_HOOK_PRIORITY, function (array $vars) {
    if (($vars['paymentmethod'] ?? '') !== SEIXASTEC_MP_MODULE) {
        return [];
    }

    return [
        'seixastec_mp_css' => <<<'CSS'
<style>
.seixastec-mp-pix img { transition: transform .2s; }
.seixastec-mp-pix img:hover { transform: scale(1.05); }
.seixastec-mp-pix .input-group { box-shadow: 0 2px 8px rgba(0,0,0,.08); border-radius: 6px; }
.seixastec-mp-boleto .btn { box-shadow: 0 2px 6px rgba(0,0,0,.12); }
.seixastec-mp-checkout .btn-primary { padding: 12px 32px; font-size: 16px; }
.seixastec-mp-choice .btn { min-width: 220px; }
.alert.seixastec-mp-notice { border-left: 4px solid #009ee3; }
</style>
CSS,
    ];
});

// =============================================================================
// HOOK 5: AdminInvoicesControlsOutput
// Adiciona botão "Sincronizar com Mercado Pago" na tela admin da fatura
// =============================================================================

add_hook('AdminInvoicesControlsOutput', SEIXASTEC_MP_HOOK_PRIORITY, function (array $vars) {
    $invoiceId = (int) ($vars['invoiceid'] ?? $_REQUEST['id'] ?? 0);
    if ($invoiceId <= 0) {
        return '';
    }

    $invoice = Capsule::table('tblinvoices')
        ->where('id', $invoiceId)
        ->select('paymentmethod', 'status')
        ->first();

    if (!$invoice || $invoice->paymentmethod !== SEIXASTEC_MP_MODULE) {
        return '';
    }

    // Processa clique no botão
    if (($_GET['mp_sync'] ?? '') === '1' && (int) ($_GET['id'] ?? 0) === $invoiceId) {
        $result = _seixastec_mp_sync_invoice($invoiceId);
        $color  = $result['success'] ? '#5cb85c' : '#d9534f';
        $icon   = $result['success'] ? '✓' : '✗';

        return <<<HTML
<div style="margin:10px 0; padding:10px; background:{$color}; color:#fff; border-radius:4px;">
    <strong>{$icon} Sincronização MP:</strong> {$result['message']}
</div>
<a href="invoices.php?action=edit&id={$invoiceId}" class="btn btn-default btn-sm">
    <i class="fa fa-refresh"></i> Sincronizar com Mercado Pago
</a>
HTML;
    }

    return <<<HTML
<a href="invoices.php?action=edit&id={$invoiceId}&mp_sync=1" class="btn btn-info btn-sm" style="margin:5px 0;">
    <i class="fa fa-refresh"></i> Sincronizar com Mercado Pago
</a>
HTML;
});

// =============================================================================
// HOOK 6: AdminAreaHeadOutput
// Mostra aviso quando o gateway tem problema de configuração
// =============================================================================

add_hook('AdminAreaHeadOutput', SEIXASTEC_MP_HOOK_PRIORITY, function (array $vars) {
    // Apenas na página de configuração do gateway
    $filename = $vars['filename'] ?? '';
    if ($filename !== 'configgateways') {
        return '';
    }

    $gateway = _seixastec_mp_gateway();
    if ($gateway === null) {
        return '';
    }

    $warnings = [];

    if (empty($gateway['webhookSecret'])) {
        $warnings[] = '⚠️ <strong>Webhook Secret</strong> não configurado. A validação HMAC está desabilitada (inseguro em produção).';
    }

    $token = (string) ($gateway['accessToken'] ?? '');
    if (str_starts_with($token, 'TEST-')) {
        $warnings[] = '🧪 Token de <strong>SANDBOX</strong> em uso. Não processará pagamentos reais.';
    }

    if (empty($warnings)) {
        return '';
    }

    $list = implode('</li><li>', $warnings);
    return <<<HTML
<style>.mp-admin-warning{position:fixed;bottom:20px;right:20px;max-width:380px;z-index:9999;padding:12px 16px;background:#fff3cd;border:1px solid #ffc107;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.15);font-size:13px;}</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (!document.querySelector('select[name="gateway"]')) return;
    var div = document.createElement('div');
    div.className = 'mp-admin-warning';
    div.innerHTML = '<strong>Mercado Pago (SeixasTec):</strong><ul style="margin:8px 0 0 18px;padding:0;"><li>{$list}</li></ul>';
    document.body.appendChild(div);
});
</script>
HTML;
});

// =============================================================================
// HOOK 7: ClientAreaPrimarySidebar
// Avisa o cliente sobre Pix pendente na sidebar da área do cliente
// =============================================================================

add_hook('ClientAreaPrimarySidebar', SEIXASTEC_MP_HOOK_PRIORITY, function ($sidebar) {
    if (!function_exists('Menu')) {
        return;
    }

    $client = \WHMCS\Session::get('uid');
    if (!$client) {
        return;
    }

    // Conta faturas pendentes neste gateway
    $count = Capsule::table('tblinvoices')
        ->where('userid', $client)
        ->where('status', 'Unpaid')
        ->where('paymentmethod', SEIXASTEC_MP_MODULE)
        ->count();

    if ($count <= 0) {
        return;
    }

    $panel = $sidebar->getChild('My Invoices');
    if ($panel) {
        $panel->addChild('mp_pending', [
            'label'    => "💸 {$count} pagamento(s) pendente(s) via Mercado Pago",
            'uri'      => 'clientarea.php?action=invoices',
            'order'    => 99,
            'bodyHtml' => '',
        ]);
    }
});

// =============================================================================
// HOOK 8: InvoiceCreation
// Validações preventivas quando fatura é criada (opcional - só loga)
// =============================================================================

add_hook('InvoiceCreation', SEIXASTEC_MP_HOOK_PRIORITY, function (array $vars) {
    $invoiceId = (int) ($vars['invoiceid'] ?? 0);
    if ($invoiceId <= 0) {
        return;
    }

    $invoice = Capsule::table('tblinvoices')
        ->where('id', $invoiceId)
        ->select('paymentmethod', 'userid')
        ->first();

    if (!$invoice || $invoice->paymentmethod !== SEIXASTEC_MP_MODULE) {
        return;
    }

    $gateway = _seixastec_mp_gateway();
    if ($gateway === null || ($gateway['paymentMode'] ?? '') !== 'boleto') {
        return;
    }

    // Para boleto, valida CPF e endereço do cliente
    $client = Capsule::table('tblclients')
        ->where('id', $invoice->userid)
        ->select('firstname', 'lastname', 'address1', 'city', 'state', 'postcode', 'tax_id')
        ->first();

    if (!$client) {
        return;
    }

    $missing = [];
    if (empty($client->tax_id))    { $missing[] = 'CPF/CNPJ (tax_id)'; }
    if (empty($client->address1))  { $missing[] = 'endereço'; }
    if (empty($client->city))      { $missing[] = 'cidade'; }
    if (empty($client->state))     { $missing[] = 'estado'; }
    if (empty($client->postcode))  { $missing[] = 'CEP'; }

    if (!empty($missing)) {
        _seixastec_mp_log('InvoiceCreation WARN', [
            'invoice_id' => $invoiceId,
            'client_id'  => $invoice->userid,
            'missing'    => $missing,
            'note'       => 'Cliente não conseguirá pagar via boleto - dados incompletos.',
        ]);
    }
});

// =============================================================================
// HELPERS INTERNOS
// =============================================================================

/**
 * Carrega parâmetros do gateway. Retorna null se desativado.
 */
function _seixastec_mp_gateway(): ?array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache ?: null;
    }

    if (!function_exists('getGatewayVariables')) {
        require_once dirname(__DIR__, 2) . '/includes/gatewayfunctions.php';
    }

    $gw = getGatewayVariables(SEIXASTEC_MP_MODULE);
    if (empty($gw['type']) || empty($gw['accessToken'])) {
        $cache = false;
        return null;
    }

    $cache = $gw;
    return $gw;
}

/**
 * Instancia a Api com base na configuração do gateway.
 */
function _seixastec_mp_api(array $gateway): Api
{
    return new Api(
        accessToken: (string) $gateway['accessToken'],
        debugMode:   ($gateway['debugLog'] ?? '') === 'on',
        productId:   $gateway['productId'] ?? null,
        moduleName:  SEIXASTEC_MP_MODULE
    );
}

/**
 * Sincroniza uma fatura específica com o Mercado Pago.
 */
function _seixastec_mp_sync_invoice(int $invoiceId): array
{
    $gateway = _seixastec_mp_gateway();
    if ($gateway === null) {
        return ['success' => false, 'message' => 'Gateway não configurado.'];
    }

    try {
        $api = _seixastec_mp_api($gateway);
    } catch (\Throwable $e) {
        return ['success' => false, 'message' => 'Falha ao iniciar API: ' . $e->getMessage()];
    }

    $search = $api->searchPaymentsByExternalReference((string) $invoiceId, 10);
    if (!$search || empty($search['results'])) {
        return ['success' => false, 'message' => 'Nenhum pagamento encontrado no Mercado Pago.'];
    }

    $applied = 0;
    foreach ($search['results'] as $payment) {
        if (($payment['status'] ?? '') !== 'approved') {
            continue;
        }

        $paymentId = (string) ($payment['id'] ?? '');
        if ($paymentId === '') {
            continue;
        }

        $exists = Capsule::table('tblaccounts')
            ->where('invoiceid', $invoiceId)
            ->where('transid', $paymentId)
            ->exists();

        if (!$exists) {
            $amount = (float) ($payment['transaction_amount'] ?? 0);
            $fee    = (float) ($payment['fee_details'][0]['amount'] ?? 0);

            addInvoicePayment($invoiceId, $paymentId, $amount, $fee, SEIXASTEC_MP_MODULE);
            logTransaction(SEIXASTEC_MP_MODULE, $payment, 'Sincronização manual via admin');
            $applied++;
        }
    }

    if ($applied > 0) {
        return ['success' => true, 'message' => "{$applied} pagamento(s) aplicado(s) com sucesso."];
    }

    return ['success' => true, 'message' => 'Fatura já está sincronizada (nenhum pagamento novo).'];
}

/**
 * Log centralizado dos hooks.
 */
function _seixastec_mp_log(string $title, mixed $data): void
{
    if (!function_exists('logModuleCall')) {
        return;
    }

    try {
        logModuleCall(
            SEIXASTEC_MP_MODULE,
            "Hook: {$title}",
            is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            '',
            '',
            ['accessToken', 'access_token', 'Bearer', 'Authorization']
        );
    } catch (\Throwable $e) {
        // silencioso
    }
}
