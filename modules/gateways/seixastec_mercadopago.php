<?php
/**
 * Mercado Pago Gateway for WHMCS
 *
 * Gateway de pagamento integrando WHMCS com Mercado Pago (Brasil).
 *
 * Métodos suportados:
 *   - Checkout Pro (redirecionamento - cartão, boleto, pix, MP wallet)
 *   - Pix direto (QR Code + Copia e Cola na fatura)
 *   - Boleto bancário direto
 *
 * Funções WHMCS implementadas:
 *   - _config()         : Campos de configuração admin
 *   - _link()           : Renderiza botão/QR code na fatura
 *   - _refund()         : Estorno via WHMCS Admin
 *   - _capture()        : Captura manual (não aplicável - MP não usa auth/capture separados aqui)
 *
 * @package   SeixasTec\MercadoPago
 * @author    Eduardo Seixas <https://github.com/eseixas>
 * @version   2.3.0
 * @license   GPL-3.0
 * @link      https://github.com/eseixas/mercadopago-whmcs
 */

declare(strict_types=1);

use WHMCS\Database\Capsule;
use WHMCS\Module\Gateway\SeixastecMercadoPago\Api;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/seixastec_mercadopago/Api.php';

use WHMCS\Module\Gateway\SeixastecMercadoPago\TemplateRenderer;

require_once __DIR__ . '/seixastec_mercadopago/TemplateRenderer.php';

// =============================================================================
// METADATA
// =============================================================================

/**
 * Define metadados do módulo para o WHMCS Admin.
 */
function seixastec_mercadopago_MetaData(): array
{
    return [
        'DisplayName'                 => 'Mercado Pago (SeixasTec)',
        'APIVersion'                  => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage'            => false,
        'gatewayLogo'                 => 'logo.png',
    ];
}

// =============================================================================
// CONFIGURAÇÃO (Admin → Setup → Payment Gateways)
// =============================================================================

/**
 * Campos de configuração exibidos no painel admin.
 */
function seixastec_mercadopago_config(): array
{
    return [
        // ----- Cabeçalho -----
        'FriendlyName' => [
            'Type'  => 'System',
            'Value' => 'Mercado Pago (SeixasTec) v2.3.0',
        ],

        // ----- Identificação no checkout do cliente -----
        'displayName' => [
            'FriendlyName' => 'Nome Exibido ao Cliente',
            'Type'         => 'text',
            'Size'         => '40',
            'Default'      => 'Mercado Pago',
            'Description'  => 'Nome que o cliente verá na escolha do método de pagamento.',
        ],

        // ----- Credenciais -----
        'accessToken' => [
            'FriendlyName' => 'Access Token',
            'Type'         => 'password',
            'Size'         => '80',
            'Default'      => '',
            'Description'  => 'Access Token de produção (APP_USR-...) ou teste (TEST-...). '
                . 'Obtenha em <a href="https://www.mercadopago.com.br/developers/panel/app" target="_blank">painel de aplicações do MP</a>.',
        ],
        'publicKey' => [
            'FriendlyName' => 'Public Key',
            'Type'         => 'text',
            'Size'         => '80',
            'Default'      => '',
            'Description'  => 'Public Key (necessária apenas para Checkout Transparente / cartão tokenizado).',
        ],
        'webhookSecret' => [
            'FriendlyName' => 'Webhook Secret',
            'Type'         => 'password',
            'Size'         => '80',
            'Default'      => '',
            'Description'  => 'Chave secreta do webhook (configure em "Suas integrações → Webhooks"). '
                . '<strong>OBRIGATÓRIO em produção.</strong>',
        ],
        'productId' => [
            'FriendlyName' => 'X-Product-Id (opcional)',
            'Type'         => 'text',
            'Size'         => '40',
            'Default'      => '',
            'Description'  => 'Identificador da aplicação no MP (header X-Product-Id). Deixe em branco se não souber.',
        ],

        // ----- Métodos de pagamento -----
        'paymentMode' => [
            'FriendlyName' => 'Modo de Pagamento',
            'Type'         => 'dropdown',
            'Options'      => [
                'checkout_pro' => 'Checkout Pro (redireciona ao MP - todos os métodos)',
                'pix'          => 'Apenas Pix (QR Code direto na fatura)',
                'boleto'       => 'Apenas Boleto',
                'pix_boleto'   => 'Pix + Boleto (cliente escolhe na fatura)',
            ],
            'Default'      => 'checkout_pro',
            'Description'  => 'Forma de apresentação do pagamento ao cliente.',
        ],

        // ----- Configurações de Pix -----
        'pixExpirationMinutes' => [
            'FriendlyName' => 'Expiração do Pix (minutos)',
            'Type'         => 'text',
            'Size'         => '10',
            'Default'      => '60',
            'Description'  => 'Tempo de validade do QR Code Pix. Mínimo: 5, Máximo: 1440 (24h).',
        ],

        // ----- Configurações de Boleto -----
        'boletoExpirationDays' => [
            'FriendlyName' => 'Vencimento do Boleto (dias)',
            'Type'         => 'text',
            'Size'         => '10',
            'Default'      => '3',
            'Description'  => 'Quantos dias úteis até o vencimento do boleto. Mínimo: 1.',
        ],

        // ----- Checkout Pro: configurações extras -----
        'excludePaymentMethods' => [
            'FriendlyName' => 'Excluir Métodos no Checkout Pro',
            'Type'         => 'text',
            'Size'         => '60',
            'Default'      => '',
            'Description'  => 'IDs separados por vírgula (ex: <code>bolbradesco,pec</code>) para ocultar no checkout.',
        ],
        'maxInstallments' => [
            'FriendlyName' => 'Máximo de Parcelas',
            'Type'         => 'text',
            'Size'         => '10',
            'Default'      => '12',
            'Description'  => 'Número máximo de parcelas no cartão (1-24).',
        ],
        'autoReturn' => [
            'FriendlyName' => 'Retorno Automático',
            'Type'         => 'yesno',
            'Default'      => 'yes',
            'Description'  => 'Marque para retornar automaticamente ao WHMCS após pagamento aprovado.',
        ],

        // ----- Comportamento / Debug -----
        'convertToBRL' => [
            'FriendlyName' => 'Forçar conversão para BRL',
            'Type'         => 'yesno',
            'Description'  => 'Se a fatura estiver em outra moeda, converte usando a taxa do WHMCS.',
        ],
        'sandboxMode' => [
            'FriendlyName' => 'Modo Sandbox',
            'Type'         => 'yesno',
            'Description'  => 'Apenas exibe um aviso no admin. O ambiente é detectado pelo token (TEST-* = sandbox).',
        ],
        'debugLog' => [
            'FriendlyName' => 'Log de Depuração',
            'Type'         => 'yesno',
            'Description'  => 'Habilita logs detalhados em <em>Utilities → Logs → Gateway Log</em>.',
        ],
    ];
}

// =============================================================================
// LINK (renderiza método de pagamento na fatura)
// =============================================================================

/**
 * Gera o HTML que será exibido na fatura do cliente.
 *
 * @param array $params Parâmetros injetados pelo WHMCS
 * @return string HTML renderizado
 */
function seixastec_mercadopago_link(array $params): string
{
    // Validação básica
    if (empty($params['accessToken'])) {
        return _seixastec_mp_alert('warning', 'Mercado Pago não está configurado (Access Token ausente).');
    }

    try {
        $api = _seixastec_mp_api($params);
    } catch (\Throwable $e) {
        return _seixastec_mp_alert('danger', 'Erro ao inicializar gateway: ' . htmlspecialchars($e->getMessage()));
    }

    // Conversão de moeda se necessário
    $amount   = (float) $params['amount'];
    $currency = $params['currency'] ?? 'BRL';

    if ($currency !== 'BRL') {
        if (($params['convertToBRL'] ?? '') !== 'on') {
            return _seixastec_mp_alert(
                'warning',
                "Fatura em {$currency}. Mercado Pago aceita apenas BRL. Habilite a conversão automática no admin."
            );
        }
        $amount = _seixastec_mp_convert_to_brl($amount, $currency);
    }

    if ($amount <= 0) {
        return _seixastec_mp_alert('danger', 'Valor da fatura inválido.');
    }

    // Verifica se já existe pagamento aprovado/pendente para esta fatura
    $existing = _seixastec_mp_find_existing_payment($api, (string) $params['invoiceid']);
    if ($existing !== null) {
        $rendered = _seixastec_mp_render_existing($existing, $params);
        if ($rendered !== null) {
            return $rendered;
        }
    }

    // Roteamento por modo de pagamento
    $mode = $params['paymentMode'] ?? 'checkout_pro';

    return match ($mode) {
        'pix'         => _seixastec_mp_render_pix($api, $params, $amount),
        'boleto'      => _seixastec_mp_render_boleto($api, $params, $amount),
        'pix_boleto'  => _seixastec_mp_render_pix_boleto($api, $params, $amount),
        default       => _seixastec_mp_render_checkout_pro($api, $params, $amount),
    };
}

// =============================================================================
// REFUND (estorno via WHMCS Admin)
// =============================================================================

/**
 * Processa estorno via WHMCS Admin → Invoice → Refund.
 */
function seixastec_mercadopago_refund(array $params): array
{
    try {
        $api = _seixastec_mp_api($params);
    } catch (\Throwable $e) {
        return [
            'status'  => 'error',
            'rawdata' => $e->getMessage(),
        ];
    }

    $paymentId = (string) ($params['transid'] ?? '');
    if ($paymentId === '') {
        return [
            'status'  => 'error',
            'rawdata' => 'Transaction ID ausente.',
        ];
    }

    // Remove sufixos tipo "-refund" caso exista
    $paymentId = preg_replace('/-refund.*$/', '', $paymentId);

    $amount = (float) ($params['amount'] ?? 0);
    $result = $api->refundPayment($paymentId, $amount > 0 ? $amount : null);

    if ($result === null) {
        return [
            'status'  => 'declined',
            'rawdata' => $api->getLastError() ?? 'Falha desconhecida no estorno.',
        ];
    }

    return [
        'status'  => 'success',
        'transid' => (string) ($result['id'] ?? $paymentId) . '-refund',
        'rawdata' => json_encode($result, JSON_UNESCAPED_UNICODE),
    ];
}

// =============================================================================
// HELPERS INTERNOS (prefixo _seixastec_mp_)
// =============================================================================

if (!function_exists('_seixastec_mp_api')) {
    /**
     * Instancia a API com os parâmetros do gateway.
     */
    function _seixastec_mp_api(array $params): Api
    {
        return new Api(
            accessToken: (string) $params['accessToken'],
            debugMode:   ($params['debugLog'] ?? '') === 'on',
            productId:   $params['productId'] ?? null,
            moduleName:  'seixastec_mercadopago'
        );
    }
}

/**
 * Converte um valor de outra moeda para BRL usando a tabela de câmbio do WHMCS.
 */
function _seixastec_mp_convert_to_brl(float $amount, string $fromCurrency): float
{
    try {
        $from = Capsule::table('tblcurrencies')->where('code', $fromCurrency)->first();
        $brl  = Capsule::table('tblcurrencies')->where('code', 'BRL')->first();

        if (!$from || !$brl || $from->rate <= 0) {
            return 0.0;
        }

        // Converte para moeda padrão e depois para BRL
        $defaultAmount = $amount / (float) $from->rate;
        return round($defaultAmount * (float) $brl->rate, 2);
    } catch (\Throwable $e) {
        return 0.0;
    }
}

/**
 * Procura pagamento existente para a fatura no Mercado Pago.
 *
 * Retorna o pagamento "mais relevante" (approved > pending > rejected).
 */
function _seixastec_mp_find_existing_payment(Api $api, string $invoiceId): ?array
{
    $search = $api->searchPaymentsByExternalReference($invoiceId, 10);
    if (!$search || empty($search['results'])) {
        return null;
    }

    $priority = ['approved' => 3, 'pending' => 2, 'in_process' => 2, 'authorized' => 1];
    $best     = null;
    $bestPrio = -1;

    foreach ($search['results'] as $payment) {
        $status = $payment['status'] ?? '';
        $prio   = $priority[$status] ?? 0;
        if ($prio > $bestPrio) {
            $best     = $payment;
            $bestPrio = $prio;
        }
    }

    return $best;
}

/**
 * Renderiza pagamento já existente (Pix pendente, aprovado, etc.).
 */
function _seixastec_mp_render_existing(array $payment, array $params): ?string
{
    $status = $payment['status'] ?? '';

    if ($status === 'approved') {
        return TemplateRenderer::render('existing_approved', [
            'paymentId' => $payment['id'] ?? '',
        ]);
    }

    if (in_array($status, ['pending', 'in_process'], true)
        && ($payment['payment_method_id'] ?? '') === 'pix'
        && !empty($payment['point_of_interaction']['transaction_data']['qr_code'])
    ) {
        return _seixastec_mp_pix_html($payment, $params);
    }

    if (in_array($status, ['pending', 'in_process'], true)
        && in_array($payment['payment_method_id'] ?? '', ['bolbradesco', 'boleto'], true)
        && !empty($payment['transaction_details']['external_resource_url'])
    ) {
        return _seixastec_mp_boleto_html($payment);
    }

    return null;
}

// ----- CHECKOUT PRO -----

function _seixastec_mp_render_checkout_pro(Api $api, array $params, float $amount): string
{
    $preference = _seixastec_mp_build_preference($params, $amount);
    $result     = $api->createPreference($preference);

    if ($result === null || empty($result['init_point'])) {
        return _seixastec_mp_alert('danger',
            'Falha ao criar preferência: ' . htmlspecialchars((string) $api->getLastError()),
            '❌'
        );
    }

    $isSandbox = $api->isSandbox();
    $url = $isSandbox && !empty($result['sandbox_init_point'])
        ? $result['sandbox_init_point']
        : $result['init_point'];

    return TemplateRenderer::render('assets')
        . TemplateRenderer::render('checkout_pro', [
            'url'       => $url,
            'label'     => $params['displayName'] ?? 'Pagar com Mercado Pago',
            'isSandbox' => $isSandbox,
        ]);
}

function _seixastec_mp_build_preference(array $params, float $amount): array
{
    $invoiceId   = (string) $params['invoiceid'];
    $systemUrl   = rtrim((string) $params['systemurl'], '/');
    $callbackUrl = $systemUrl . '/modules/gateways/callback/seixastec_mercadopago.php';
    $returnUrl   = $systemUrl . '/viewinvoice.php?id=' . $invoiceId;

    $excluded = [];
    if (!empty($params['excludePaymentMethods'])) {
        foreach (explode(',', $params['excludePaymentMethods']) as $id) {
            $id = trim($id);
            if ($id !== '') {
                $excluded[] = ['id' => $id];
            }
        }
    }

    $maxInstallments = max(1, min(24, (int) ($params['maxInstallments'] ?? 12)));

    return [
        'items' => [[
            'id'          => 'invoice-' . $invoiceId,
            'title'       => 'Fatura #' . $invoiceId . ' - ' . ($params['companyname'] ?? 'WHMCS'),
            'description' => mb_substr((string) ($params['description'] ?? 'Fatura ' . $invoiceId), 0, 250),
            'quantity'    => 1,
            'currency_id' => 'BRL',
            'unit_price'  => round($amount, 2),
        ]],
        'payer' => [
            'name'    => (string) ($params['clientdetails']['firstname'] ?? ''),
            'surname' => (string) ($params['clientdetails']['lastname']  ?? ''),
            'email'   => (string) ($params['clientdetails']['email']     ?? ''),
        ],
        'external_reference'  => $invoiceId,
        'notification_url'    => $callbackUrl,
        'back_urls' => [
            'success' => $returnUrl,
            'pending' => $returnUrl,
            'failure' => $returnUrl,
        ],
        'auto_return'         => ($params['autoReturn'] ?? 'on') === 'on' ? 'approved' : null,
        'payment_methods'     => [
            'excluded_payment_methods' => $excluded,
            'installments'             => $maxInstallments,
        ],
        'statement_descriptor' => mb_substr((string) ($params['companyname'] ?? 'WHMCS'), 0, 22),
        'binary_mode'          => false,
    ];
}

// ----- PIX -----

function _seixastec_mp_render_pix(Api $api, array $params, float $amount): string
{
    $payment = $api->createPayment(_seixastec_mp_build_pix($params, $amount));

    if ($payment === null) {
        return _seixastec_mp_alert('danger',
            'Falha ao gerar Pix: ' . htmlspecialchars((string) $api->getLastError())
        );
    }

    return _seixastec_mp_pix_html($payment, $params);
}

function _seixastec_mp_build_pix(array $params, float $amount): array
{
    $invoiceId   = (string) $params['invoiceid'];
    $systemUrl   = rtrim((string) $params['systemurl'], '/');
    $callbackUrl = $systemUrl . '/modules/gateways/callback/seixastec_mercadopago.php';

    $minutes = max(5, min(1440, (int) ($params['pixExpirationMinutes'] ?? 60)));
    $expires = (new \DateTimeImmutable('+' . $minutes . ' minutes'))->format('Y-m-d\TH:i:s.000P');

    return [
        'transaction_amount' => round($amount, 2),
        'description'        => 'Fatura #' . $invoiceId,
        'payment_method_id'  => 'pix',
        'external_reference' => $invoiceId,
        'notification_url'   => $callbackUrl,
        'date_of_expiration' => $expires,
        'payer' => [
            'email'      => (string) ($params['clientdetails']['email'] ?? ''),
            'first_name' => (string) ($params['clientdetails']['firstname'] ?? ''),
            'last_name'  => (string) ($params['clientdetails']['lastname'] ?? ''),
        ],
    ];
}

function _seixastec_mp_pix_html(array $payment, array $params): string
{
    $qrBase64 = $payment['point_of_interaction']['transaction_data']['qr_code_base64'] ?? '';
    $qrCode   = $payment['point_of_interaction']['transaction_data']['qr_code']        ?? '';
    $expires  = $payment['date_of_expiration'] ?? null;

    if ($qrCode === '') {
        return _seixastec_mp_alert('warning', 'QR Code Pix indisponível. Recarregue a página.', '⚠️');
    }

    $expiresFormatted = '';
    if ($expires) {
        try {
            $expiresFormatted = (new \DateTimeImmutable($expires))->format('d/m/Y H:i');
        } catch (\Throwable $e) { /* ignora */ }
    }

    return TemplateRenderer::render('assets')
        . TemplateRenderer::render('pix', [
            'qrCodeBase64' => $qrBase64,
            'qrCode'       => $qrCode,
            'expiresAt'    => $expiresFormatted,
            'invoiceId'    => $params['invoiceid'] ?? 'main',
            'autoRefresh'  => 15,
        ]);
}

// ----- BOLETO -----

function _seixastec_mp_render_boleto(Api $api, array $params, float $amount): string
{
    // Boleto exige CPF e endereço completo
    $validation = _seixastec_mp_validate_boleto_data($params);
    if ($validation !== null) {
        return _seixastec_mp_alert('warning', $validation);
    }

    $payment = $api->createPayment(_seixastec_mp_build_boleto($params, $amount));

    if ($payment === null) {
        return _seixastec_mp_alert('danger',
            'Falha ao gerar boleto: ' . htmlspecialchars((string) $api->getLastError())
        );
    }

    return _seixastec_mp_boleto_html($payment);
}

function _seixastec_mp_validate_boleto_data(array $params): ?string
{
    $client = $params['clientdetails'] ?? [];

    if (empty($client['tax_id']) && empty($client['cpf']) && empty($client['customfields'])) {
        return 'Boleto requer CPF cadastrado. Atualize seus dados antes de prosseguir.';
    }

    $required = ['firstname', 'lastname', 'address1', 'city', 'state', 'postcode'];
    foreach ($required as $field) {
        if (empty($client[$field])) {
            return "Boleto requer endereço completo. Campo faltante: <strong>{$field}</strong>.";
        }
    }

    return null;
}

function _seixastec_mp_build_boleto(array $params, float $amount): array
{
    $invoiceId   = (string) $params['invoiceid'];
    $systemUrl   = rtrim((string) $params['systemurl'], '/');
    $callbackUrl = $systemUrl . '/modules/gateways/callback/seixastec_mercadopago.php';

    $days    = max(1, (int) ($params['boletoExpirationDays'] ?? 3));
    $expires = (new \DateTimeImmutable('+' . $days . ' days'))->format('Y-m-d\TH:i:s.000P');

    $client = $params['clientdetails'];
    $cpf    = preg_replace('/\D/', '', (string) ($client['tax_id'] ?? $client['cpf'] ?? ''));

    return [
        'transaction_amount' => round($amount, 2),
        'description'        => 'Fatura #' . $invoiceId,
        'payment_method_id'  => 'bolbradesco',
        'external_reference' => $invoiceId,
        'notification_url'   => $callbackUrl,
        'date_of_expiration' => $expires,
        'payer' => [
            'email'      => (string) $client['email'],
            'first_name' => (string) $client['firstname'],
            'last_name'  => (string) $client['lastname'],
            'identification' => [
                'type'   => strlen($cpf) === 14 ? 'CNPJ' : 'CPF',
                'number' => $cpf,
            ],
            'address' => [
                'zip_code'      => preg_replace('/\D/', '', (string) $client['postcode']),
                'street_name'   => (string) $client['address1'],
                'street_number' => 'S/N',
                'neighborhood'  => (string) ($client['address2'] ?? 'Centro'),
                'city'          => (string) $client['city'],
                'federal_unit'  => (string) $client['state'],
            ],
        ],
    ];
}

function _seixastec_mp_boleto_html(array $payment): string
{
    $url     = $payment['transaction_details']['external_resource_url'] ?? '';
    $barcode = $payment['barcode']['content'] ?? '';
    $expires = $payment['date_of_expiration'] ?? null;

    if ($url === '') {
        return _seixastec_mp_alert('warning', 'Boleto indisponível. Recarregue a página.', '⚠️');
    }

    $expiresFormatted = '';
    if ($expires) {
        try {
            $expiresFormatted = (new \DateTimeImmutable($expires))->format('d/m/Y');
        } catch (\Throwable $e) { /* ignora */ }
    }

    return TemplateRenderer::render('assets')
        . TemplateRenderer::render('boleto', [
            'url'       => $url,
            'barcode'   => $barcode,
            'expiresAt' => $expiresFormatted,
        ]);
}

// ----- PIX + BOLETO (cliente escolhe) -----

function _seixastec_mp_render_pix_boleto(Api $api, array $params, float $amount): string
{
    $choice = $_GET['mp_method'] ?? null;

    if ($choice === 'pix') {
        return _seixastec_mp_render_pix($api, $params, $amount);
    }
    if ($choice === 'boleto') {
        return _seixastec_mp_render_boleto($api, $params, $amount);
    }

    $invoiceId = (int) $params['invoiceid'];
    $systemUrl = rtrim((string) $params['systemurl'], '/');
    $base      = "{$systemUrl}/viewinvoice.php?id={$invoiceId}";

    return TemplateRenderer::render('assets')
        . TemplateRenderer::render('choice', [
            'pixUrl'    => "{$base}&mp_method=pix",
            'boletoUrl' => "{$base}&mp_method=boleto",
        ]);
}

// ----- UI HELPERS -----

function _seixastec_mp_alert(string $type, string $message, string $icon = ''): string
{
    return TemplateRenderer::render('alert', [
        'type'    => in_array($type, ['success','info','warning','danger'], true) ? $type : 'info',
        'message' => $message,
        'icon'    => $icon,
    ]);
}
