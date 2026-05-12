<?php
/**
 * Mercado Pago - Webhook / IPN Handler
 *
 * Endpoint público que recebe notificações do Mercado Pago e processa
 * pagamentos confirmados, registrando-os automaticamente no WHMCS.
 *
 * URL pública:
 *   https://SEU_WHMCS/modules/gateways/callback/seixastec_mercadopago.php
 *
 * Camadas de proteção:
 *   1. Validação HMAC-SHA256 do header x-signature (anti-spoofing)
 *   2. Anti-replay: rejeita timestamps >5min (janela configurável)
 *   3. File lock exclusivo por payment_id (anti race-condition)
 *   4. Re-verificação do pagamento via API (não confia no payload)
 *   5. Verificação de duplicatas em tblaccounts (anti-double-charge)
 *   6. Verificação de external_reference vs invoiceid (anti-tampering)
 *   7. Log estruturado de todas as decisões
 *
 * Eventos suportados:
 *   - payment.created
 *   - payment.updated
 *   - merchant_order
 *
 * Compatível com: WHMCS 9.x | PHP 8.3 | Mercado Pago API v1
 *
 * Autor: Eduardo Seixas
 * Atualizado: 2026
 * Licença: GPL-3.0
 */

declare(strict_types=1);

use WHMCS\Database\Capsule;
use WHMCS\Module\Gateway\SeixastecMercadoPago\Api;

// =======================================================================
// BOOTSTRAP WHMCS
// =======================================================================

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../seixastec_mercadopago/Api.php';

// =======================================================================
// CONSTANTES
// =======================================================================

/** Tolerância máxima para timestamp da assinatura (segundos). */
const MP_SIGNATURE_MAX_AGE = 300; // 5 minutos

/** Tempo máximo aguardando file lock (segundos). */
const MP_LOCK_TIMEOUT = 10;

/** Nome interno do gateway. */
const MP_GATEWAY_MODULE = 'seixastec_mercadopago';

// =======================================================================
// 1. CARREGAR CONFIGURAÇÃO DO GATEWAY
// =======================================================================

$gatewayParams = getGatewayVariables(MP_GATEWAY_MODULE);

if (empty($gatewayParams['type'])) {
    mp_webhook_respond(503, 'Gateway not activated');
}

$debugMode = ($gatewayParams['debugMode'] ?? '') === 'on';

// =======================================================================
// 2. CAPTURAR PAYLOAD E HEADERS
// =======================================================================

$rawBody = file_get_contents('php://input') ?: '';
$payload = json_decode($rawBody, true);

if (!is_array($payload)) {
    mp_log_callback('ERROR', 'Invalid JSON body', ['raw' => substr($rawBody, 0, 500)]);
    mp_webhook_respond(400, 'Invalid JSON');
}

$headers = mp_get_request_headers();
$signatureHeader = $headers['x-signature'] ?? $headers['X-Signature'] ?? '';
$requestIdHeader = $headers['x-request-id'] ?? $headers['X-Request-Id'] ?? '';

if ($debugMode) {
    mp_log_callback('DEBUG', 'Webhook received', [
        'payload'    => $payload,
        'signature'  => $signatureHeader !== '' ? substr($signatureHeader, 0, 40) . '...' : '(empty)',
        'request_id' => $requestIdHeader,
        'ip'         => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    ]);
}

// =======================================================================
// 3. VALIDAR ASSINATURA HMAC-SHA256
// =======================================================================

$webhookSecret = trim((string) ($gatewayParams['webhookSecret'] ?? ''));

if ($webhookSecret !== '') {
    $dataId = mp_extract_data_id($payload);

    if (!mp_validate_signature($signatureHeader, $requestIdHeader, $dataId, $webhookSecret)) {
        mp_log_callback('SECURITY', 'Invalid HMAC signature', [
            'signature'  => substr($signatureHeader, 0, 40),
            'request_id' => $requestIdHeader,
            'data_id'    => $dataId,
            'ip'         => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);
        mp_webhook_respond(401, 'Invalid signature');
    }
}

// =======================================================================
// 4. ROTEAR EVENTO POR TIPO
// =======================================================================

$eventType = (string) ($payload['type'] ?? $payload['topic'] ?? '');
$action    = (string) ($payload['action'] ?? '');

switch ($eventType) {
    case 'payment':
        $paymentId = mp_extract_data_id($payload);
        if ($paymentId === '') {
            mp_webhook_respond(400, 'Missing payment ID');
        }
        mp_process_payment($paymentId, $gatewayParams, $action);
        break;

    case 'merchant_order':
        $orderId = mp_extract_data_id($payload);
        if ($orderId === '') {
            mp_webhook_respond(400, 'Missing order ID');
        }
        mp_process_merchant_order($orderId, $gatewayParams);
        break;

    default:
        // Outros eventos são reconhecidos mas ignorados (test, chargeback, etc.)
        mp_log_callback('INFO', 'Event ignored', ['type' => $eventType, 'action' => $action]);
        mp_webhook_respond(200, 'Event ignored');
}

// =======================================================================
// FUNÇÕES DE PROCESSAMENTO
// =======================================================================

/**
 * Processa um evento de pagamento (payment.created / payment.updated).
 */
function mp_process_payment(string $paymentId, array $params, string $action): void
{
    // ── 1. File lock contra processamento concorrente
    $lockFile = sys_get_temp_dir() . '/mp_payment_' . preg_replace('/[^a-zA-Z0-9]/', '', $paymentId) . '.lock';
    $lockHandle = fopen($lockFile, 'c+');

    if (!$lockHandle) {
        mp_log_callback('ERROR', 'Failed to open lock file', ['payment_id' => $paymentId]);
        mp_webhook_respond(500, 'Lock failure');
    }

    $startTime = time();
    while (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        if ((time() - $startTime) > MP_LOCK_TIMEOUT) {
            fclose($lockHandle);
            mp_log_callback('WARN', 'Lock timeout', ['payment_id' => $paymentId]);
            mp_webhook_respond(409, 'Concurrent processing');
        }
        usleep(200000); // 200ms
    }

    try {
        // ── 2. Consulta o pagamento na API (fonte da verdade)
        $accessToken = mp_get_access_token($params);
        $api = new Api($accessToken, ($params['debugMode'] ?? '') === 'on');

        $payment = $api->getPayment($paymentId);

        if ($payment === null) {
            mp_log_callback('ERROR', 'Failed to fetch payment', [
                'payment_id' => $paymentId,
                'api_error'  => $api->getLastError(),
                'http_code'  => $api->getLastHttpCode(),
            ]);
            mp_webhook_respond(502, 'API fetch failed');
        }

        $status            = (string) ($payment['status'] ?? '');
        $externalReference = (string) ($payment['external_reference'] ?? '');
        $amountPaid        = (float) ($payment['transaction_amount'] ?? 0);
        $amountRefunded    = (float) ($payment['transaction_amount_refunded'] ?? 0);

        // ── 3. Valida external_reference (= ID da fatura)
        $invoiceId = (int) $externalReference;
        if ($invoiceId <= 0) {
            mp_log_callback('ERROR', 'Invalid external_reference', [
                'payment_id'         => $paymentId,
                'external_reference' => $externalReference,
            ]);
            mp_webhook_respond(400, 'Invalid external_reference');
        }

        // ── 4. Confirma que a fatura existe no WHMCS
        $invoiceValidation = checkCbInvoiceID($invoiceId, MP_GATEWAY_MODULE);
        if (!$invoiceValidation) {
            mp_log_callback('ERROR', 'Invoice not found in WHMCS', [
                'invoice_id' => $invoiceId,
                'payment_id' => $paymentId,
            ]);
            mp_webhook_respond(404, 'Invoice not found');
        }

        // ── 5. Persiste/atualiza dados PIX e Boleto na tabela auxiliar
        mp_update_transaction_data($invoiceId, $paymentId, $payment);

        // ── 6. Roteia por status
        switch ($status) {
            case 'approved':
                mp_handle_approved($invoiceId, $paymentId, $amountPaid, $payment, $params);
                break;

            case 'refunded':
            case 'charged_back':
                mp_handle_refunded($invoiceId, $paymentId, $amountRefunded, $status);
                break;

            case 'pending':
            case 'in_process':
            case 'in_mediation':
                mp_log_callback('INFO', 'Payment pending', [
                    'invoice_id' => $invoiceId,
                    'payment_id' => $paymentId,
                    'status'     => $status,
                ]);
                break;

            case 'rejected':
            case 'cancelled':
                mp_log_callback('INFO', 'Payment rejected/cancelled', [
                    'invoice_id' => $invoiceId,
                    'payment_id' => $paymentId,
                    'status'     => $status,
                    'detail'     => $payment['status_detail'] ?? '',
                ]);
                break;

            default:
                mp_log_callback('WARN', 'Unknown payment status', [
                    'invoice_id' => $invoiceId,
                    'payment_id' => $paymentId,
                    'status'     => $status,
                ]);
        }

        mp_webhook_respond(200, 'OK');

    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        @unlink($lockFile);
    }
}

/**
 * Processa pagamento aprovado: registra no WHMCS via AddInvoicePayment.
 */
function mp_handle_approved(int $invoiceId, string $paymentId, float $amount, array $payment, array $params): void
{
    // Verificação anti-duplicação direta na base
    $exists = Capsule::table('tblaccounts')
        ->where('invoiceid', $invoiceId)
        ->where('transid', $paymentId)
        ->exists();

    if ($exists) {
        mp_log_callback('INFO', 'Payment already registered', [
            'invoice_id' => $invoiceId,
            'payment_id' => $paymentId,
        ]);
        return;
    }

    // Calcula a taxa cobrada pelo Mercado Pago (se houver detalhe)
    $fee = 0.0;
    if (!empty($payment['fee_details']) && is_array($payment['fee_details'])) {
        foreach ($payment['fee_details'] as $feeDetail) {
            $fee += (float) ($feeDetail['amount'] ?? 0);
        }
    }

    // Registra no WHMCS
    addInvoicePayment(
        $invoiceId,
        $paymentId,
        $amount,
        $fee,
        MP_GATEWAY_MODULE
    );

    // Atualiza status local
    try {
        Capsule::table('mod_seixastec_mp_transactions')
            ->where('invoice_id', $invoiceId)
            ->update([
                'status'     => 'approved',
                'paid_at'    => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    } catch (\Throwable $e) {
        // não bloqueia o fluxo principal
    }

    mp_log_callback('SUCCESS', 'Payment approved and registered', [
        'invoice_id' => $invoiceId,
        'payment_id' => $paymentId,
        'amount'     => $amount,
        'fee'        => $fee,
        'method'     => $payment['payment_method_id'] ?? 'unknown',
    ]);

    if (function_exists('logActivity')) {
        logActivity(sprintf(
            '[Mercado Pago] Pagamento aprovado | Fatura #%d | Payment %s | R$ %s',
            $invoiceId,
            $paymentId,
            number_format($amount, 2, ',', '.')
        ));
    }
}

/**
 * Processa reembolso/chargeback notificado via webhook.
 */
function mp_handle_refunded(int $invoiceId, string $paymentId, float $amountRefunded, string $status): void
{
    try {
        Capsule::table('mod_seixastec_mp_transactions')
            ->where('invoice_id', $invoiceId)
            ->update([
                'status'          => $status,
                'amount_refunded' => $amountRefunded,
                'updated_at'      => date('Y-m-d H:i:s'),
            ]);
    } catch (\Throwable $e) {
        // ignora — tabela pode não existir
    }

    mp_log_callback('INFO', 'Refund/chargeback notification', [
        'invoice_id'      => $invoiceId,
        'payment_id'      => $paymentId,
        'status'          => $status,
        'amount_refunded' => $amountRefunded,
    ]);

    if (function_exists('logActivity')) {
        logActivity(sprintf(
            '[Mercado Pago] %s notificado | Fatura #%d | Payment %s | R$ %s',
            strtoupper($status),
            $invoiceId,
            $paymentId,
            number_format($amountRefunded, 2, ',', '.')
        ));
    }
}

/**
 * Processa merchant_order: itera pagamentos vinculados e processa cada um.
 */
function mp_process_merchant_order(string $orderId, array $params): void
{
    $accessToken = mp_get_access_token($params);
    $api = new Api($accessToken, ($params['debugMode'] ?? '') === 'on');

    $order = $api->getMerchantOrder($orderId);

    if ($order === null || empty($order['payments'])) {
        mp_log_callback('INFO', 'Merchant order without payments', ['order_id' => $orderId]);
        mp_webhook_respond(200, 'No payments');
    }

    foreach ($order['payments'] as $paymentRef) {
        $pid = (string) ($paymentRef['id'] ?? '');
        if ($pid !== '') {
            // Reprocessa cada pagamento individualmente (com seu próprio lock)
            mp_process_payment($pid, $params, 'merchant_order.relay');
        }
    }

    mp_webhook_respond(200, 'OK');
}

// =======================================================================
// HELPERS
// =======================================================================

/**
 * Valida a assinatura HMAC-SHA256 do header x-signature.
 *
 * Formato esperado do header:
 *   ts=1700000000,v1=hash_hex
 *
 * Manifest assinado:
 *   id:{data_id};request-id:{x-request-id};ts:{ts};
 */
function mp_validate_signature(string $signatureHeader, string $requestId, string $dataId, string $secret): bool
{
    if ($signatureHeader === '' || $secret === '') {
        return false;
    }

    // Parse "ts=...,v1=..."
    $parts = [];
    foreach (explode(',', $signatureHeader) as $segment) {
        if (str_contains($segment, '=')) {
            [$k, $v] = explode('=', $segment, 2);
            $parts[trim($k)] = trim($v);
        }
    }

    $ts = $parts['ts'] ?? '';
    $v1 = $parts['v1'] ?? '';

    if ($ts === '' || $v1 === '' || !ctype_digit($ts)) {
        return false;
    }

    // Anti-replay: timestamp deve estar dentro da janela
    $age = abs(time() - (int) $ts);
    if ($age > MP_SIGNATURE_MAX_AGE) {
        return false;
    }

    // Reconstrói o manifest e calcula o HMAC esperado
    $manifest = sprintf('id:%s;request-id:%s;ts:%s;', $dataId, $requestId, $ts);
    $expected = hash_hmac('sha256', $manifest, $secret);

    return hash_equals($expected, $v1);
}

/**
 * Extrai o ID do recurso do payload (suporta múltiplos formatos).
 */
function mp_extract_data_id(array $payload): string
{
    // Formato novo: { "data": { "id": "..." } }
    if (isset($payload['data']['id'])) {
        return (string) $payload['data']['id'];
    }

    // Formato antigo (IPN): { "id": "..." } ou query-string ?id=...&topic=...
    if (isset($payload['id'])) {
        return (string) $payload['id'];
    }

    // Fallback: query-string GET
    if (!empty($_GET['id'])) {
        return (string) $_GET['id'];
    }

    if (!empty($_GET['data_id'])) {
        return (string) $_GET['data_id'];
    }

    return '';
}

/**
 * Atualiza/persiste dados PIX e Boleto extraídos da resposta da API.
 */
function mp_update_transaction_data(int $invoiceId, string $paymentId, array $payment): void
{
    $method = (string) ($payment['payment_method_id'] ?? '');
    $data = [
        'payment_id' => $paymentId,
        'method'     => $method,
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    // PIX
    if ($method === 'pix' && !empty($payment['point_of_interaction']['transaction_data'])) {
        $pix = $payment['point_of_interaction']['transaction_data'];
        $data['pix_qr_base64']  = (string) ($pix['qr_code_base64'] ?? '');
        $data['pix_copia_cola'] = (string) ($pix['qr_code'] ?? '');
    }

    // Boleto
    if (in_array($method, ['bolbradesco', 'pec', 'boleto'], true)
        && !empty($payment['transaction_details'])) {
        $td = $payment['transaction_details'];
        $data['boleto_url']  = (string) ($td['external_resource_url'] ?? '');
        $data['boleto_linha'] = (string) ($payment['barcode']['content'] ?? '');
    }

    try {
        Capsule::table('mod_seixastec_mp_transactions')->updateOrInsert(
            ['invoice_id' => $invoiceId],
            $data + ['created_at' => Capsule::raw('COALESCE(created_at, NOW())')]
        );
    } catch (\Throwable $e) {
        // Tabela pode não existir; o hook de install resolve
    }
}

/**
 * Retorna Access Token conforme modo sandbox/produção.
 */
function mp_get_access_token(array $params): string
{
    $isSandbox = ($params['sandboxMode'] ?? '') === 'on';
    return $isSandbox
        ? (string) ($params['accessTokenSandbox'] ?? '')
        : (string) ($params['accessTokenProd'] ?? '');
}

/**
 * Captura headers HTTP de forma compatível com qualquer SAPI.
 */
function mp_get_request_headers(): array
{
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if ($headers !== false) {
            // Normaliza para lowercase
            $normalized = [];
            foreach ($headers as $key => $value) {
                $normalized[strtolower($key)] = $value;
            }
            return $normalized;
        }
    }

    // Fallback via $_SERVER
    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (str_starts_with($key, 'HTTP_')) {
            $headerName = strtolower(str_replace('_', '-', substr($key, 5)));
            $headers[$headerName] = $value;
        }
    }
    return $headers;
}

/**
 * Log estruturado do callback no Module Log do WHMCS.
 */
function mp_log_callback(string $level, string $message, array $context = []): void
{
    if (!function_exists('logModuleCall')) {
        return;
    }

    try {
        logModuleCall(
            MP_GATEWAY_MODULE,
            "Webhook [{$level}] {$message}",
            json_encode($context, JSON_UNESCAPED_UNICODE),
            $message,
            '',
            ['access_token', 'accessToken', 'webhookSecret', 'Authorization', 'Bearer']
        );
    } catch (\Throwable $e) {
        // silencioso
    }
}

/**
 * Envia resposta HTTP e encerra a execução.
 */
function mp_webhook_respond(int $statusCode, string $message): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status'  => $statusCode < 400 ? 'ok' : 'error',
        'message' => $message,
        'time'    => date('c'),
    ]);
    exit;
}
