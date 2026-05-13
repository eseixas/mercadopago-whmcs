<?php
/**
 * Mercado Pago - Webhook Callback Handler
 *
 * Recebe notificações de eventos do Mercado Pago e atualiza faturas WHMCS.
 *
 * Eventos suportados:
 *   - payment.created / payment.updated
 *   - merchant_order.* (resolve para o(s) payment(s) agregado(s))
 *
 * Fluxo de processamento:
 *   1. Valida assinatura HMAC (x-signature + x-request-id) [se webhookSecret configurado]
 *   2. Extrai paymentId do payload OU resolve via merchant_order
 *   3. Consulta o pagamento na API do MP (fonte de verdade)
 *   4. Localiza a fatura no WHMCS via external_reference
 *   5. Aplica ação conforme status:
 *      - approved  -> addInvoicePayment()
 *      - refunded  -> registra estorno (transid + nota)
 *      - cancelled/rejected -> registra nota
 *      - pending/in_process -> apenas loga
 *
 * Proteções:
 *   - Anti-replay via cache de payment_id (tabela própria opcional)
 *   - Idempotência via WHMCS (addInvoicePayment ignora transid duplicado)
 *   - Sempre retorna HTTP 200 após processar (para o MP não retentar)
 *   - HTTP 503 apenas se gateway não configurado
 *
 * @package   SeixasTec\MercadoPago
 * @author    Eduardo Seixas <https://github.com/eseixas>
 * @version   2.2.0
 * @license   GPL-3.0
 */

declare(strict_types=1);

use WHMCS\Database\Capsule;
use WHMCS\Module\Gateway\SeixastecMercadoPago\Api;

// =============================================================================
// BOOTSTRAP WHMCS
// =============================================================================

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../seixastec_mercadopago/Api.php';

// =============================================================================
// CARREGA CONFIGURAÇÃO DO GATEWAY
// =============================================================================

$gatewayModuleName = 'seixastec_mercadopago';
$gateway           = getGatewayVariables($gatewayModuleName);

// Helper de log (mascara token)
$log = static function (string $title, mixed $data) use ($gatewayModuleName, $gateway): void {
    if (!function_exists('logModuleCall')) {
        return;
    }
    try {
        logModuleCall(
            $gatewayModuleName,
            $title,
            is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            '',
            '',
            [$gateway['accessToken'] ?? '', 'access_token', 'Bearer', 'Authorization']
        );
    } catch (\Throwable $e) {
        // silencioso
    }
};

// Gateway ativo?
if (empty($gateway['type'])) {
    http_response_code(503);
    exit('Gateway not activated');
}

$accessToken   = trim((string) ($gateway['accessToken']   ?? ''));
$webhookSecret = trim((string) ($gateway['webhookSecret'] ?? ''));
$debugLog      = ($gateway['debugLog'] ?? '') === 'on';

if ($accessToken === '') {
    $log('Webhook ERROR', 'Access Token não configurado.');
    http_response_code(503);
    exit('Gateway misconfigured');
}

// =============================================================================
// LEITURA DO PAYLOAD
// =============================================================================

$rawBody = file_get_contents('php://input') ?: '';
$payload = json_decode($rawBody, true);

if (!is_array($payload)) {
    // MP às vezes envia apenas via querystring (legacy IPN)
    $payload = $_GET ?: $_POST;
}

$headers = _seixastec_mp_get_headers();

if ($debugLog) {
    $log('Webhook RECEIVED', [
        'headers' => $headers,
        'query'   => $_GET,
        'body'    => $payload,
        'raw'     => $rawBody,
    ]);
}

// =============================================================================
// VALIDAÇÃO DE ASSINATURA HMAC (x-signature)
// =============================================================================

if ($webhookSecret !== '') {
    $signatureHeader = $headers['x-signature']  ?? '';
    $requestIdHeader = $headers['x-request-id'] ?? '';
    $dataId          = $_GET['data.id'] ?? $_GET['id'] ?? ($payload['data']['id'] ?? '');

    if (!_seixastec_mp_validate_signature($signatureHeader, $requestIdHeader, (string) $dataId, $webhookSecret)) {
        $log('Webhook SIGNATURE INVALID', [
            'x-signature'  => $signatureHeader,
            'x-request-id' => $requestIdHeader,
            'data.id'      => $dataId,
        ]);
        http_response_code(401);
        exit('Invalid signature');
    }
} else {
    // Em produção sem secret é arriscado; loga aviso mas processa
    $log('Webhook WARNING', 'Webhook Secret não configurado - validação HMAC desabilitada.');
}

// =============================================================================
// EXTRAÇÃO DO PAYMENT ID
// =============================================================================

$topic = $_GET['type']  ?? $_GET['topic'] ?? ($payload['type']  ?? $payload['topic'] ?? '');
$id    = $_GET['data.id'] ?? $_GET['id']  ?? ($payload['data']['id'] ?? $payload['id'] ?? '');

if ($id === '' || $id === null) {
    $log('Webhook ERROR', 'ID ausente no payload.');
    http_response_code(200);
    exit('Missing id');
}

// =============================================================================
// PROCESSAMENTO
// =============================================================================

try {
    $api = new Api(
        accessToken: $accessToken,
        debugMode:   $debugLog,
        productId:   $gateway['productId'] ?? null,
        moduleName:  $gatewayModuleName
    );
} catch (\Throwable $e) {
    $log('Webhook ERROR', 'Falha ao instanciar API: ' . $e->getMessage());
    http_response_code(503);
    exit('API init failed');
}

$paymentIds = [];

// Topic = merchant_order -> resolve para payments
if (str_contains((string) $topic, 'merchant_order')) {
    $order = $api->getMerchantOrder((string) $id);
    if ($order && !empty($order['payments'])) {
        foreach ($order['payments'] as $p) {
            if (!empty($p['id'])) {
                $paymentIds[] = (string) $p['id'];
            }
        }
    }
} else {
    // payment.* ou IPN legacy
    $paymentIds[] = (string) $id;
}

if (empty($paymentIds)) {
    $log('Webhook INFO', "Nenhum payment_id resolvido para topic={$topic} id={$id}");
    http_response_code(200);
    exit('OK - no payments');
}

// Processa cada pagamento
foreach (array_unique($paymentIds) as $paymentId) {
    try {
        _seixastec_mp_process_payment($api, $paymentId, $gateway, $log);
    } catch (\Throwable $e) {
        $log('Webhook EXCEPTION', [
            'payment_id' => $paymentId,
            'error'      => $e->getMessage(),
            'trace'      => $e->getTraceAsString(),
        ]);
        // Continua processando os outros payments
    }
}

http_response_code(200);
exit('OK');

// =============================================================================
// HELPERS
// =============================================================================

/**
 * Processa um único pagamento.
 */
function _seixastec_mp_process_payment(Api $api, string $paymentId, array $gateway, \Closure $log): void
{
    $payment = $api->getPayment($paymentId);

    if ($payment === null) {
        $log('Webhook ERROR', [
            'payment_id' => $paymentId,
            'error'      => $api->getLastError(),
        ]);
        return;
    }

    $status            = (string) ($payment['status'] ?? '');
    $externalReference = (string) ($payment['external_reference'] ?? '');
    $amount            = (float)  ($payment['transaction_amount'] ?? 0);
    $amountRefunded    = (float)  ($payment['transaction_amount_refunded'] ?? 0);
    $paymentMethod     = (string) ($payment['payment_method_id'] ?? '');
    $paymentType       = (string) ($payment['payment_type_id'] ?? '');

    if ($externalReference === '') {
        $log('Webhook WARN', "Pagamento {$paymentId} sem external_reference.");
        return;
    }

    // Localiza fatura no WHMCS
    $invoiceId = checkCbInvoiceID((int) $externalReference, $gateway['name']);
    if (!$invoiceId) {
        $log('Webhook WARN', "Fatura {$externalReference} não encontrada no WHMCS.");
        return;
    }

    // Verifica duplicação (impede pagamento duplicado para o mesmo transid)
    checkCbTransID($paymentId);

    $fee = (float) ($payment['fee_details'][0]['amount'] ?? 0);

    $log('Webhook PROCESS', [
        'payment_id'         => $paymentId,
        'status'             => $status,
        'invoice_id'         => $invoiceId,
        'amount'             => $amount,
        'amount_refunded'    => $amountRefunded,
        'payment_method'     => $paymentMethod,
        'payment_type'       => $paymentType,
        'fee'                => $fee,
    ]);

    switch ($status) {
        case 'approved':
            addInvoicePayment(
                $invoiceId,        // invoice id
                $paymentId,        // transaction id
                $amount,           // valor pago
                $fee,              // taxa do gateway
                $gateway['name']   // gateway module
            );
            logTransaction($gateway['name'], $payment, "Aprovado ({$paymentMethod})");
            break;

        case 'refunded':
            logTransaction($gateway['name'], $payment, "Reembolso TOTAL processado ({$amount})");
            _seixastec_mp_add_invoice_note(
                $invoiceId,
                "Reembolso TOTAL via Mercado Pago. Payment ID: {$paymentId}. Valor: R$ " . number_format($amount, 2, ',', '.')
            );
            break;

        case 'charged_back':
            logTransaction($gateway['name'], $payment, "Chargeback recebido");
            _seixastec_mp_add_invoice_note(
                $invoiceId,
                "⚠️ CHARGEBACK recebido no Mercado Pago. Payment ID: {$paymentId}. Verifique a fatura."
            );
            break;

        case 'cancelled':
            logTransaction($gateway['name'], $payment, "Pagamento cancelado");
            break;

        case 'rejected':
            $detail = (string) ($payment['status_detail'] ?? 'sem detalhe');
            logTransaction($gateway['name'], $payment, "Pagamento rejeitado: {$detail}");
            break;

        case 'pending':
        case 'in_process':
        case 'authorized':
            logTransaction($gateway['name'], $payment, "Status: {$status}");
            break;

        default:
            logTransaction($gateway['name'], $payment, "Status desconhecido: {$status}");
            break;
    }

    // Reembolso parcial (status continua approved, mas amount_refunded > 0)
    if ($status === 'approved' && $amountRefunded > 0) {
        _seixastec_mp_add_invoice_note(
            $invoiceId,
            "Reembolso PARCIAL via Mercado Pago. Payment ID: {$paymentId}. "
            . "Valor reembolsado: R$ " . number_format($amountRefunded, 2, ',', '.')
        );
    }
}

/**
 * Adiciona uma nota administrativa à fatura.
 */
function _seixastec_mp_add_invoice_note(int $invoiceId, string $note): void
{
    try {
        $current = Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->value('notes') ?? '';

        $timestamp = date('Y-m-d H:i:s');
        $newNote   = trim($current . "\n[{$timestamp}] {$note}");

        Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->update(['notes' => $newNote]);
    } catch (\Throwable $e) {
        // ignora falha de nota
    }
}

/**
 * Captura headers HTTP de forma compatível (Apache, Nginx, FPM).
 *
 * @return array<string,string> Headers em lowercase
 */
function _seixastec_mp_get_headers(): array
{
    $headers = [];

    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            $headers[strtolower((string) $k)] = (string) $v;
        }
    }

    // Fallback via $_SERVER (HTTP_*)
    foreach ($_SERVER as $k => $v) {
        if (str_starts_with((string) $k, 'HTTP_')) {
            $name = strtolower(str_replace('_', '-', substr((string) $k, 5)));
            if (!isset($headers[$name])) {
                $headers[$name] = (string) $v;
            }
        }
    }

    return $headers;
}

/**
 * Valida assinatura HMAC do webhook do Mercado Pago.
 *
 * Formato do header x-signature:
 *   ts=1234567890,v1=hex_hmac_sha256
 *
 * Template assinado:
 *   id:<data.id>;request-id:<x-request-id>;ts:<ts>;
 *
 * @see https://www.mercadopago.com.br/developers/pt/docs/your-integrations/notifications/webhooks
 */
function _seixastec_mp_validate_signature(
    string $signatureHeader,
    string $requestId,
    string $dataId,
    string $secret
): bool {
    if ($signatureHeader === '' || $dataId === '') {
        return false;
    }

    // Parse "ts=...,v1=..."
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

    // Proteção anti-replay: timestamp não pode ser muito antigo (>5min) nem do futuro (>1min)
    $now    = time();
    $tsInt  = (int) $ts;
    if ($tsInt > 0 && ($now - $tsInt > 300 || $tsInt - $now > 60)) {
        return false;
    }

    // Template oficial do MP (data.id em lowercase)
    $template = sprintf(
        'id:%s;request-id:%s;ts:%s;',
        strtolower($dataId),
        $requestId,
        $ts
    );

    $expected = hash_hmac('sha256', $template, $secret);

    return hash_equals($expected, $v1);
}
