<?php
/**
 * Mercado Pago - Endpoint de Processamento de Pagamento (AJAX)
 *
 * Recebe os dados do Payment Brick (pay.php), cria o pagamento
 * via API do Mercado Pago e retorna JSON com instruções de redirecionamento.
 *
 * Fluxo:
 *   pay.php (Brick onSubmit) → process.php → Api::createPayment()
 *                                          → resposta JSON → redirecionamento
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

// Bootstrap WHMCS
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/Api.php';

// ---------------------------------------------------------------------------
// Configurações de resposta
// ---------------------------------------------------------------------------
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate');

/**
 * Resposta JSON padronizada e encerra a execução.
 */
function respond(bool $success, string $message = '', array $extra = [], int $httpCode = 200): never
{
    http_response_code($httpCode);
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Log estruturado no Module Log do WHMCS.
 */
function mpLog(string $action, $request, $response): void
{
    logModuleCall(
        'seixastec_mercadopago',
        $action,
        is_string($request)  ? $request  : json_encode($request,  JSON_UNESCAPED_UNICODE),
        is_string($response) ? $response : json_encode($response, JSON_UNESCAPED_UNICODE),
        '',
        ['accessToken', 'access_token', 'token', 'security_code', 'card_number']
    );
}

// ---------------------------------------------------------------------------
// 1. Validações de método HTTP e autenticação
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Método não permitido.', [], 405);
}

if (!isset($_SESSION['uid']) || (int) $_SESSION['uid'] <= 0) {
    respond(false, 'Sessão expirada. Faça login novamente.', [], 401);
}
$clientId = (int) $_SESSION['uid'];

// Header AJAX obrigatório (mitiga CSRF básico)
$xhr = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
if (strcasecmp($xhr, 'XMLHttpRequest') !== 0) {
    respond(false, 'Requisição inválida.', [], 400);
}

// ---------------------------------------------------------------------------
// 2. Decodifica payload JSON
// ---------------------------------------------------------------------------
$rawBody = file_get_contents('php://input') ?: '';
$input   = json_decode($rawBody, true);

if (!is_array($input) || json_last_error() !== JSON_ERROR_NONE) {
    respond(false, 'Payload JSON inválido.', [], 400);
}

$invoiceId           = (int) ($input['invoice_id'] ?? 0);
$selectedPaymentType = (string) ($input['payment_method'] ?? '');
$formData            = is_array($input['form_data'] ?? null) ? $input['form_data'] : [];

if ($invoiceId <= 0) {
    respond(false, 'ID de fatura inválido.', [], 400);
}

// ---------------------------------------------------------------------------
// 3. Carrega gateway e valida estado
// ---------------------------------------------------------------------------
$gateway = getGatewayVariables('seixastec_mercadopago');
if (empty($gateway['type'])) {
    respond(false, 'Gateway inativo.', [], 503);
}

$sandboxMode = ($gateway['sandboxMode'] ?? '') === 'on';
$accessToken = trim((string) ($gateway['accessToken'] ?? ''));

if (empty($accessToken)) {
    respond(false, 'Credenciais não configuradas.', [], 503);
}

$systemUrl  = rtrim($gateway['systemurl'], '/');
$returnUrl  = $systemUrl . '/viewinvoice.php?id=' . $invoiceId;
$webhookUrl = $systemUrl . '/modules/gateways/callback/seixastec_mercadopago.php';

// ---------------------------------------------------------------------------
// 4. Valida fatura e propriedade
// ---------------------------------------------------------------------------
try {
    $invoice = Capsule::table('tblinvoices')
        ->where('id', $invoiceId)
        ->where('userid', $clientId)
        ->first();

    if (!$invoice) {
        respond(false, 'Fatura não encontrada.', [], 404);
    }

    if ($invoice->status === 'Paid') {
        respond(true, 'Fatura já está paga.', ['redirect' => $returnUrl]);
    }

    if (in_array($invoice->status, ['Cancelled', 'Refunded'], true)) {
        respond(false, 'Fatura não pode ser paga (status: ' . $invoice->status . ').', [], 400);
    }

    $client = Capsule::table('tblclients')->where('id', $clientId)->first();
    if (!$client) {
        respond(false, 'Cliente não encontrado.', [], 404);
    }
} catch (Throwable $e) {
    mpLog('process_db_error', $input, $e->getMessage());
    respond(false, 'Erro ao carregar dados.', [], 500);
}

// ---------------------------------------------------------------------------
// 5. Recalcula valor com taxa (autoritativo no servidor!)
// ---------------------------------------------------------------------------
$baseAmount = (float) $invoice->total;
$taxa       = (float) ($gateway['feePercent'] ?? 0);
$amount     = $taxa > 0 ? round($baseAmount * (1 + $taxa / 100), 2) : $baseAmount;

if ($amount <= 0) {
    respond(false, 'Valor da fatura inválido.', [], 400);
}

// 🔐 Tolerância R$ 0,02 contra adulteração do amount enviado pelo Brick
$brickAmount = (float) ($formData['transaction_amount'] ?? $amount);
if (abs($brickAmount - $amount) > 0.02) {
    mpLog('process_amount_mismatch', [
        'expected' => $amount,
        'received' => $brickAmount,
        'invoice'  => $invoiceId,
    ], 'Valor adulterado pelo cliente');
    respond(false, 'Inconsistência no valor do pagamento.', [], 400);
}

// ---------------------------------------------------------------------------
// 6. Idempotência (mesma fatura + método + valor = mesma chave)
// ---------------------------------------------------------------------------
$idempotencyKey = sprintf(
    'inv-%d-%s-%s',
    $invoiceId,
    substr(preg_replace('/[^a-z0-9]/i', '', $selectedPaymentType), 0, 16) ?: 'unknown',
    substr(md5((string) $amount), 0, 10)
);

// ---------------------------------------------------------------------------
// 7. Monta payload base do pagamento
// ---------------------------------------------------------------------------
$payerEmail = filter_var($formData['payer']['email'] ?? $client->email, FILTER_VALIDATE_EMAIL)
    ?: $client->email;

$basePayload = [
    'transaction_amount' => $amount,
    'description'        => 'Fatura #' . $invoiceId . ' - ' . ($gateway['companyname'] ?? 'WHMCS'),
    'external_reference' => (string) $invoiceId,
    'notification_url'   => $webhookUrl,
    'statement_descriptor' => substr((string) ($gateway['companyname'] ?? 'WHMCS'), 0, 22),
    '_idempotency_key'   => $idempotencyKey,
    'payer' => [
        'email'      => $payerEmail,
        'first_name' => (string) ($client->firstname ?? ''),
        'last_name'  => (string) ($client->lastname ?? ''),
    ],
];

// Identificação (CPF/CNPJ) — vinda do Brick OU do custom field
$identification = $formData['payer']['identification'] ?? null;
if (!is_array($identification) || empty($identification['number'])) {
    try {
        $docFromCf = Capsule::table('tblcustomfieldsvalues as v')
            ->join('tblcustomfields as f', 'f.id', '=', 'v.fieldid')
            ->where('v.relid', $clientId)
            ->where('f.type', 'client')
            ->whereIn('f.fieldname', ['CPF', 'CNPJ', 'CPF/CNPJ', 'Documento'])
            ->value('v.value');

        $docNumber = preg_replace('/\D/', '', (string) $docFromCf);
        if ($docNumber !== '' && (strlen($docNumber) === 11 || strlen($docNumber) === 14)) {
            $identification = [
                'type'   => strlen($docNumber) === 11 ? 'CPF' : 'CNPJ',
                'number' => $docNumber,
            ];
        }
    } catch (Throwable $e) {
        // Silencioso
    }
}

if (is_array($identification) && !empty($identification['number'])) {
    $basePayload['payer']['identification'] = [
        'type'   => strtoupper((string) ($identification['type'] ?? 'CPF')),
        'number' => preg_replace('/\D/', '', (string) $identification['number']),
    ];
}

// ---------------------------------------------------------------------------
// 8. Despacha por método de pagamento
// ---------------------------------------------------------------------------
$api = new Api($accessToken);

try {
    $paymentTypeNormalized = strtolower($selectedPaymentType);

    // ---- PIX ----
    if (in_array($paymentTypeNormalized, ['bank_transfer', 'pix'], true)) {
        $pixExpirationMinutes = max(5, (int) ($gateway['pixExpiration'] ?? 30));

        $payload = array_merge($basePayload, [
            'payment_method_id'  => 'pix',
            'date_of_expiration' => date('Y-m-d\TH:i:s.000P', time() + ($pixExpirationMinutes * 60)),
        ]);

        $result = $api->createPayment($payload);
        mpLog('PIX_CREATE', $payload, $result ?? $api->getLastError());

        if (!$result || empty($result['id'])) {
            respond(false, 'Falha ao gerar PIX: ' . ($api->getLastError() ?? 'erro desconhecido'), [], 502);
        }

        storeTransaction($invoiceId, $result, 'pix', $amount);

        $poi = $result['point_of_interaction']['transaction_data'] ?? [];

        respond(true, 'PIX gerado com sucesso.', [
            'payment_id' => (string) $result['id'],
            'status'     => $result['status'] ?? 'pending',
            'pix' => [
                'qr_base64'  => $poi['qr_code_base64'] ?? '',
                'qr_code'    => $poi['qr_code'] ?? '',
                'expires_at' => $result['date_of_expiration'] ?? '',
            ],
            'redirect' => $returnUrl,
        ]);
    }

    // ---- BOLETO ----
    if (in_array($paymentTypeNormalized, ['ticket', 'bolbradesco', 'pec'], true)) {
        $payload = array_merge($basePayload, [
            'payment_method_id' => $formData['payment_method_id'] ?? 'bolbradesco',
        ]);

        // Boleto exige endereço — tenta enriquecer com dados do cliente
        if (!empty($client->address1)) {
            $payload['payer']['address'] = [
                'zip_code'      => preg_replace('/\D/', '', (string) $client->postcode),
                'street_name'   => (string) $client->address1,
                'street_number' => (string) ($client->address2 ?: 'S/N'),
                'neighborhood'  => (string) ($client->state ?: 'Centro'),
                'city'          => (string) $client->city,
                'federal_unit'  => substr((string) $client->state, 0, 2),
            ];
        }

        $result = $api->createPayment($payload);
        mpLog('BOLETO_CREATE', $payload, $result ?? $api->getLastError());

        if (!$result || empty($result['id'])) {
            respond(false, 'Falha ao gerar boleto: ' . ($api->getLastError() ?? 'erro'), [], 502);
        }

        storeTransaction($invoiceId, $result, 'ticket', $amount);

        $td = $result['transaction_details'] ?? [];

        respond(true, 'Boleto gerado com sucesso.', [
            'payment_id' => (string) $result['id'],
            'status'     => $result['status'] ?? 'pending',
            'ticket' => [
                'boleto_url'     => $td['external_resource_url'] ?? '',
                'digitable_line' => $result['barcode']['content'] ?? '',
            ],
            'redirect' => $td['external_resource_url'] ?? $returnUrl,
        ]);
    }

    // ---- CARTÃO (Crédito / Débito) ----
    if (in_array($paymentTypeNormalized, ['credit_card', 'debit_card'], true)) {
        $cardToken    = (string) ($formData['token'] ?? '');
        $installments = max(1, (int) ($formData['installments'] ?? 1));
        $pmId         = (string) ($formData['payment_method_id'] ?? '');
        $issuerId     = $formData['issuer_id'] ?? null;

        if ($cardToken === '' || $pmId === '') {
            respond(false, 'Dados do cartão incompletos.', [], 400);
        }

        $maxInstallments = max(1, (int) ($gateway['maxInstallments'] ?? 12));
        if ($installments > $maxInstallments) {
            $installments = $maxInstallments;
        }

        $payload = array_merge($basePayload, [
            'token'             => $cardToken,
            'installments'      => $installments,
            'payment_method_id' => $pmId,
            'capture'           => true,
        ]);

        if ($issuerId !== null && $issuerId !== '') {
            $payload['issuer_id'] = (string) $issuerId;
        }

        $result = $api->createPayment($payload);
        mpLog('CARD_CREATE', $payload, $result ?? $api->getLastError());

        if (!$result || empty($result['id'])) {
            respond(false, 'Falha no pagamento: ' . ($api->getLastError() ?? 'erro'), [], 502);
        }

        $status       = (string) ($result['status'] ?? 'pending');
        $statusDetail = (string) ($result['status_detail'] ?? '');

        storeTransaction($invoiceId, $result, 'card', $amount);

        // Aprovado imediatamente? Já registra no WHMCS (otimização de UX)
        if ($status === 'approved') {
            registerWhmcsPayment($invoiceId, (string) $result['id'], $amount);
        }

        $userMessage = mapCardStatusMessage($status, $statusDetail);

        respond(
            $status === 'approved' || $status === 'in_process',
            $userMessage,
            [
                'payment_id'    => (string) $result['id'],
                'status'        => $status,
                'status_detail' => $statusDetail,
                'redirect'      => $returnUrl,
            ]
        );
    }

    respond(false, 'Método de pagamento não suportado: ' . $selectedPaymentType, [], 400);

} catch (Throwable $e) {
    mpLog('process_exception', $input, [
        'message' => $e->getMessage(),
        'trace'   => $e->getTraceAsString(),
    ]);
    respond(false, 'Erro interno ao processar pagamento.', [], 500);
}

// ===========================================================================
// HELPERS
// ===========================================================================

/**
 * Persiste a transação na tabela local de auditoria.
 */
function storeTransaction(int $invoiceId, array $payment, string $method, float $amount): void
{
    try {
        Capsule::table('mod_seixastec_mp_transactions')->updateOrInsert(
            ['payment_id' => (string) $payment['id']],
            [
                'invoice_id'  => $invoiceId,
                'status'      => (string) ($payment['status'] ?? 'pending'),
                'method'      => $method,
                'amount'      => $amount,
                'updated_at'  => date('Y-m-d H:i:s'),
            ]
        );
    } catch (Throwable $e) {
        // Auditoria silenciosa — não impede o fluxo
    }
}

/**
 * Registra pagamento no WHMCS evitando duplicidade.
 */
function registerWhmcsPayment(int $invoiceId, string $transactionId, float $amount): void
{
    try {
        $exists = Capsule::table('tblaccounts')
            ->where('invoiceid', $invoiceId)
            ->where('transid', $transactionId)
            ->where('gateway', 'seixastec_mercadopago')
            ->exists();

        if ($exists) {
            return;
        }

        addInvoicePayment(
            $invoiceId,
            $transactionId,
            $amount,
            0,
            'seixastec_mercadopago'
        );
    } catch (Throwable $e) {
        mpLog('whmcs_add_payment_error', [
            'invoice' => $invoiceId,
            'txn'     => $transactionId,
        ], $e->getMessage());
    }
}

/**
 * Traduz status/status_detail do MP em mensagem amigável.
 *
 * Referência: https://www.mercadopago.com.br/developers/pt/docs/checkout-api/response-handling/collection-results
 */
function mapCardStatusMessage(string $status, string $detail): string
{
    if ($status === 'approved') {
        return 'Pagamento aprovado! Redirecionando...';
    }

    if ($status === 'in_process') {
        return 'Pagamento em análise. Você será notificado ao ser aprovado.';
    }

    // status = rejected
    return match ($detail) {
        'cc_rejected_bad_filled_card_number' => 'Número do cartão incorreto.',
        'cc_rejected_bad_filled_date'        => 'Data de validade incorreta.',
        'cc_rejected_bad_filled_security_code' => 'Código de segurança (CVV) incorreto.',
        'cc_rejected_bad_filled_other'       => 'Algum dado do cartão está incorreto.',
        'cc_rejected_insufficient_amount'    => 'Cartão sem limite suficiente.',
        'cc_rejected_high_risk'              => 'Pagamento recusado por análise de risco.',
        'cc_rejected_call_for_authorize'     => 'Autorize o pagamento junto ao seu banco.',
        'cc_rejected_card_disabled'          => 'Cartão desativado. Contate o emissor.',
        'cc_rejected_duplicated_payment'     => 'Pagamento duplicado.',
        'cc_rejected_max_attempts'           => 'Limite de tentativas atingido.',
        'cc_rejected_other_reason'           => 'Pagamento recusado pelo banco emissor.',
        default => 'Pagamento recusado. Tente outro método ou cartão.',
    };
}
