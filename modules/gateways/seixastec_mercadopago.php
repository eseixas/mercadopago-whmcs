<?php
/**
 * Mercado Pago - Módulo Principal do Gateway WHMCS
 *
 * Arquivo carregado pelo WHMCS para registrar o gateway. Define:
 *   - seixastec_mercadopago_MetaData()  Metadados do módulo
 *   - seixastec_mercadopago_config()    Campos do painel admin
 *   - seixastec_mercadopago_link()      Botão "Pagar agora" na fatura
 *   - seixastec_mercadopago_refund()    Handler de reembolso
 *
 * Fluxo:
 *   1. Admin configura credenciais via _config()
 *   2. Cliente abre fatura → _link() gera preferência no MP
 *   3. Cliente é redirecionado ao Checkout Pro
 *   4. Webhook (callback/) confirma pagamento e baixa a fatura
 *   5. Admin pode reembolsar via _refund()
 *
 * Compatível com: WHMCS 8.x / 9.x | PHP 8.1+ | Mercado Pago API v1
 *
 * Autor: Eduardo Seixas
 * Versão: 2.1.0
 * Atualizado: 2026-05-11
 * Licença: GPL-3.0
 */

declare(strict_types=1);

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

// Dependências carregadas sob demanda para evitar erros de autoloader no WHMCS

// =======================================================================
// METADADOS DO MÓDULO
// =======================================================================

function seixastec_mercadopago_MetaData(): array
{
    return [
        'DisplayName'                 => 'Mercado Pago (PIX, Boleto e Cartão)',
        'APIVersion'                  => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage'            => false,
    ];
}

// =======================================================================
// CONFIGURAÇÃO (Painel Admin)
// =======================================================================

function seixastec_mercadopago_config(): array
{
    $customFieldsOptions = seixastec_mercadopago_buildCustomFieldsDropdown();
    $webhookUrl          = seixastec_mercadopago_getWebhookUrl();

    return [
        'FriendlyName' => [
            'Type'  => 'System',
            'Value' => 'Mercado Pago (PIX, Boleto e Cartão)',
        ],

        // ─────────── CREDENCIAIS ───────────
        'sectionCredentials' => [
            'FriendlyName' => '<b style="color:#009ee3">🔑 Credenciais do Mercado Pago</b>',
            'Type'         => 'System',
            'Description'  => 'Obtenha em <a href="https://www.mercadopago.com.br/developers/panel/app" target="_blank">Painel do Desenvolvedor → Suas Integrações → Credenciais</a>.',
        ],
        'accessTokenProd' => [
            'FriendlyName' => 'Access Token (Produção)',
            'Type'         => 'password',
            'Size'         => '80',
            'Default'      => '',
            'Description'  => 'Token de produção (APP_USR-...). Use apenas em ambiente real.',
        ],
        'accessTokenSandbox' => [
            'FriendlyName' => 'Access Token (Sandbox)',
            'Type'         => 'password',
            'Size'         => '80',
            'Default'      => '',
            'Description'  => 'Token de teste (TEST-...). Use para testes sem cobrança real.',
        ],
        'sandboxMode' => [
            'FriendlyName' => 'Modo Sandbox',
            'Type'         => 'yesno',
            'Description'  => 'Quando ativo, usa o token Sandbox e URLs de teste do MP.',
        ],
        'publicKeyProd' => [
            'FriendlyName' => 'Public Key (Produção)',
            'Type'         => 'password',
            'Size'         => '80',
            'Default'      => '',
            'Description'  => 'Chave pública de produção (APP_USR-...). Necessária para o Payment Brick.',
        ],
        'publicKeySandbox' => [
            'FriendlyName' => 'Public Key (Sandbox)',
            'Type'         => 'password',
            'Size'         => '80',
            'Default'      => '',
            'Description'  => 'Chave pública de teste (TEST-...). Necessária para o Payment Brick.',
        ],

        // ─────────── WEBHOOK ───────────
        'sectionWebhook' => [
            'FriendlyName' => '<b style="color:#009ee3">🔔 Webhook (Notificações)</b>',
            'Type'         => 'System',
            'Description'  => 'Configure em <a href="https://www.mercadopago.com.br/developers/panel/webhooks" target="_blank">Painel do Desenvolvedor → Webhooks</a>.<br>'
                . '<b>URL do webhook:</b> <code style="background:#f0f0f0;padding:4px 8px;border-radius:3px;font-size:12px;">'
                . htmlspecialchars($webhookUrl, ENT_QUOTES, 'UTF-8') . '</code>',
        ],
        'webhookSecret' => [
            'FriendlyName' => 'Webhook Secret (HMAC)',
            'Type'         => 'password',
            'Size'         => '80',
            'Default'      => '',
            'Description'  => 'Chave secreta gerada no painel do MP. Quando preenchida, todas as notificações são validadas via HMAC-SHA256. '
                . '<b>Recomendado em produção.</b>',
        ],

        // ─────────── TAXAS ───────────
        'sectionFees' => [
            'FriendlyName' => '<b style="color:#009ee3">💰 Taxas Adicionais</b>',
            'Type'         => 'System',
            'Description'  => 'Taxas somadas ao valor da fatura (repassadas ao cliente).',
        ],
        'feePercent' => [
            'FriendlyName' => 'Taxa Percentual (%)',
            'Type'         => 'text',
            'Size'         => '6',
            'Default'      => '0',
            'Description'  => 'Ex.: 2.5 — somada como percentual sobre o valor.',
        ],
        'feeFixed' => [
            'FriendlyName' => 'Taxa Fixa (R$)',
            'Type'         => 'text',
            'Size'         => '6',
            'Default'      => '0',
            'Description'  => 'Ex.: 2.00 — valor fixo adicional.',
        ],

        // ─────────── VENCIMENTO E MULTA ───────────
        'sectionDue' => [
            'FriendlyName' => '<b style="color:#009ee3">📅 Vencimento, Multa e Juros</b>',
            'Type'         => 'System',
        ],
        'dueDays' => [
            'FriendlyName' => 'Vencimento padrão (dias)',
            'Type'         => 'text',
            'Size'         => '4',
            'Default'      => '3',
            'Description'  => 'Dias até o vencimento de boletos / preferências.',
        ],
        'finePercent' => [
            'FriendlyName' => 'Multa por atraso (%)',
            'Type'         => 'text',
            'Size'         => '4',
            'Default'      => '2',
            'Description'  => 'Máximo legal: 2% (CDC Art. 52 §1º).',
        ],
        'interestMonthly' => [
            'FriendlyName' => 'Juros proporcional (% ao mês)',
            'Type'         => 'text',
            'Size'         => '4',
            'Default'      => '1',
            'Description'  => 'Ex.: 1% ao mês = 0,033%/dia.',
        ],

        // ─────────── COMPORTAMENTO ───────────
        'sectionBehavior' => [
            'FriendlyName' => '<b style="color:#009ee3">⚙️ Comportamento</b>',
            'Type'         => 'System',
        ],
        'generateForAll' => [
            'FriendlyName' => 'Gerar para todos os pedidos?',
            'Type'         => 'yesno',
            'Description'  => 'Sim = qualquer fatura terá PIX/Boleto pré-gerados. Não = apenas ao selecionar o gateway.',
        ],
        'cpfCnpjField' => [
            'FriendlyName' => 'Campo CPF/CNPJ',
            'Type'         => 'dropdown',
            'Options'      => $customFieldsOptions,
            'Description'  => 'Campo personalizado do cliente que armazena CPF/CNPJ.',
        ],
        'validateDocument' => [
            'FriendlyName' => 'Validar CPF/CNPJ no checkout?',
            'Type'         => 'yesno',
            'Description'  => 'Bloqueia pagamento se documento for matematicamente inválido.',
        ],
        'paymentMethods' => [
            'FriendlyName' => 'Metodos de pagamento (Brick)',
            'Type'         => 'dropdown',
            'Options'      => [
                'all'      => 'Todos os metodos',
                'pix'      => 'Apenas PIX',
                'card'     => 'Apenas Cartao (credito + debito)',
                'ticket'   => 'Apenas Boleto',
                'pix_card' => 'PIX + Cartao',
            ],
            'Default'      => 'all',
            'Description'  => 'Define quais metodos serao exibidos no Payment Brick.',
        ],
        'maxInstallments' => [
            'FriendlyName' => 'Parcelas maximas',
            'Type'         => 'text',
            'Size'         => '2',
            'Default'      => '12',
            'Description'  => 'Numero maximo de parcelas para cartao de credito.',
        ],
        'pixExpiration' => [
            'FriendlyName' => 'Expiracao PIX (minutos)',
            'Type'         => 'text',
            'Size'         => '4',
            'Default'      => '30',
            'Description'  => 'Tempo de expiracao do QR Code PIX em minutos.',
        ],

        // ─────────── DEBUG ───────────
        'sectionDebug' => [
            'FriendlyName' => '<b style="color:#009ee3">🐛 Debug</b>',
            'Type'         => 'System',
        ],
        'debugMode' => [
            'FriendlyName' => 'Modo Debug',
            'Type'         => 'yesno',
            'Description'  => 'Registra todas as chamadas à API no Module Log do WHMCS.',
        ],
    ];
}

// =======================================================================
// LINK DE PAGAMENTO (Botão "Pagar agora")
// =======================================================================

function seixastec_mercadopago_link(array $params): string
{
    try {
        seixastec_mercadopago_load_dependencies();
    } catch (\Throwable $e) {
        return seixastec_mercadopago_renderError('Erro no Módulo Mercado Pago', $e->getMessage());
    }

    $invoiceId = (int) $params['invoiceid'];
    $amount    = (float) $params['amount'];

    // Calcula valor final com taxas
    $feePercent  = (float) ($params['feePercent'] ?? 0);
    $feeFixed    = (float) ($params['feeFixed'] ?? 0);
    $finalAmount = round($amount + ($amount * $feePercent / 100) + $feeFixed, 2);

    // Validação opcional de CPF/CNPJ
    if (($params['validateDocument'] ?? '') === 'on') {
        $docFieldId = (int) ($params['cpfCnpjField'] ?? 0);
        $document   = seixastec_mercadopago_getClientDocument(
            (int) ($params['clientdetails']['userid'] ?? 0),
            $docFieldId
        );

        if ($document === '' || !\WHMCS\Module\Gateway\SeixastecMercadoPago\Validator::validate($document)) {
            return seixastec_mercadopago_renderError(
                'CPF/CNPJ inválido ou não preenchido',
                'Atualize seu cadastro com um documento válido antes de pagar.'
            );
        }
    }

    try {
        $accessToken = seixastec_mercadopago_getAccessToken($params);
        $api         = new \WHMCS\Module\Gateway\SeixastecMercadoPago\Api($accessToken, ($params['debugMode'] ?? '') === 'on');

        $preferenceData = seixastec_mercadopago_buildPreference($params, $invoiceId, $finalAmount);
        $preference     = $api->createPreference($preferenceData);

        if ($preference === null || empty($preference['init_point'])) {
            return seixastec_mercadopago_renderError(
                'Erro ao gerar pagamento',
                $api->getLastError() ?? 'Tente novamente em alguns instantes.'
            );
        }

        $checkoutUrl = ($params['sandboxMode'] ?? '') === 'on'
            ? ($preference['sandbox_init_point'] ?? $preference['init_point'])
            : $preference['init_point'];

        seixastec_mercadopago_storePreference(
            $invoiceId,
            (string) $preference['id'],
            $finalAmount
        );

        return seixastec_mercadopago_renderButton($checkoutUrl, $finalAmount, $amount);

    } catch (\Throwable $e) {
        if (function_exists('logActivity')) {
            logActivity('[Mercado Pago] Erro link fatura #' . $invoiceId . ': ' . $e->getMessage());
        }
        return seixastec_mercadopago_renderError('Erro inesperado', $e->getMessage());
    }
}

// =======================================================================
// REEMBOLSO
// =======================================================================

function seixastec_mercadopago_refund(array $params): array
{
    try {
        seixastec_mercadopago_load_dependencies();
    } catch (\Throwable $e) {
        return [
            'status'  => 'error',
            'rawdata' => ['error' => 'Falha de inicialização: ' . $e->getMessage()],
        ];
    }

    $paymentId = trim((string) ($params['transid'] ?? ''));
    $amount    = (float) ($params['amount'] ?? 0);

    if ($paymentId === '') {
        return [
            'status'  => 'error',
            'rawdata' => ['error' => 'Transaction ID ausente.'],
        ];
    }

    try {
        $accessToken = seixastec_mercadopago_getAccessToken($params);
        $api         = new \WHMCS\Module\Gateway\SeixastecMercadoPago\Api($accessToken, ($params['debugMode'] ?? '') === 'on');

        $refund = $api->refundPayment($paymentId, $amount > 0 ? $amount : null);

        if ($refund === null) {
            return [
                'status'  => 'declined',
                'rawdata' => [
                    'error'     => $api->getLastError(),
                    'http_code' => $api->getLastHttpCode(),
                ],
            ];
        }

        return [
            'status'  => 'success',
            'transid' => (string) ($refund['id'] ?? $paymentId),
            'rawdata' => $refund,
        ];

    } catch (\Throwable $e) {
        return [
            'status'  => 'error',
            'rawdata' => ['exception' => $e->getMessage()],
        ];
    }
}

// =======================================================================
// HELPERS INTERNOS
// =======================================================================

/**
 * Carrega as classes auxiliares do módulo, validando ambiente e arquivos.
 */
function seixastec_mercadopago_load_dependencies(): void
{
    if (PHP_VERSION_ID < 80100) {
        throw new \RuntimeException('O módulo Mercado Pago requer PHP 8.1 ou superior (Atual: ' . PHP_VERSION . '). Atualize a versão do PHP.');
    }

    $apiPath       = __DIR__ . '/seixastec_mercadopago/Api.php';
    $validatorPath = __DIR__ . '/seixastec_mercadopago/Validator.php';

    if (!file_exists($apiPath) || !file_exists($validatorPath)) {
        throw new \RuntimeException('Arquivos base do módulo não encontrados (Api.php ou Validator.php). Faça o upload completo novamente.');
    }

    require_once $apiPath;
    require_once $validatorPath;
}

/**
 * Retorna Access Token conforme modo sandbox/produção.
 */
function seixastec_mercadopago_getAccessToken(array $params): string
{
    $isSandbox = ($params['sandboxMode'] ?? '') === 'on';
    $token = $isSandbox
        ? (string) ($params['accessTokenSandbox'] ?? '')
        : (string) ($params['accessTokenProd'] ?? '');

    if ($token === '') {
        throw new \RuntimeException(
            $isSandbox
                ? 'Access Token de Sandbox não configurado.'
                : 'Access Token de Produção não configurado.'
        );
    }

    return $token;
}

/**
 * Monta a URL pública do webhook a partir do SystemURL.
 */
function seixastec_mercadopago_getWebhookUrl(): string
{
    try {
        $systemUrl = (string) Capsule::table('tblconfiguration')
            ->where('setting', 'SystemURL')
            ->value('value');
        return rtrim($systemUrl, '/') . '/modules/gateways/callback/seixastec_mercadopago.php';
    } catch (\Throwable $e) {
        return '/modules/gateways/callback/seixastec_mercadopago.php';
    }
}

/**
 * Constrói o payload completo da preferência MP.
 */
function seixastec_mercadopago_buildPreference(array $params, int $invoiceId, float $amount): array
{
    $client    = $params['clientdetails'];
    $systemUrl = rtrim((string) ($params['systemurl'] ?? ''), '/');
    $returnUrl = (string) ($params['returnurl'] ?? '');

    $docFieldId = (int) ($params['cpfCnpjField'] ?? 0);
    $document   = seixastec_mercadopago_getClientDocument(
        (int) ($client['userid'] ?? 0),
        $docFieldId
    );
    $docInfo = $document !== '' ? \WHMCS\Module\Gateway\SeixastecMercadoPago\Validator::inspect($document) : null;

    $phoneDigits = preg_replace('/\D/', '', (string) ($client['phonenumber'] ?? '')) ?? '';

    $preference = [
        'items' => [[
            'id'          => (string) $invoiceId,
            'title'       => 'Fatura #' . $invoiceId,
            'description' => 'Fatura ' . $invoiceId . ' - ' . ($params['companyname'] ?? 'WHMCS'),
            'quantity'    => 1,
            'unit_price'  => $amount,
            'currency_id' => 'BRL',
        ]],
        'payer' => [
            'name'    => (string) ($client['firstname'] ?? ''),
            'surname' => (string) ($client['lastname'] ?? ''),
            'email'   => (string) ($client['email'] ?? ''),
            'phone'   => [
                'area_code' => substr($phoneDigits, 0, 2),
                'number'    => substr($phoneDigits, 2),
            ],
            'address' => [
                'zip_code'      => preg_replace('/\D/', '', (string) ($client['postcode'] ?? '')) ?? '',
                'street_name'   => (string) ($client['address1'] ?? ''),
                'street_number' => '0',
            ],
        ],
        'external_reference' => (string) $invoiceId,
        'notification_url'   => $systemUrl . '/modules/gateways/callback/seixastec_mercadopago.php',
        'back_urls' => [
            'success' => $returnUrl,
            'pending' => $returnUrl,
            'failure' => $returnUrl,
        ],
        'auto_return'          => 'approved',
        'binary_mode'          => false,
        'statement_descriptor' => substr((string) ($params['companyname'] ?? 'WHMCS'), 0, 22),
        'expires'              => true,
        'expiration_date_to'   => date('c', strtotime('+' . (int) ($params['dueDays'] ?? 3) . ' days')),
    ];

    if ($docInfo !== null && $docInfo['valid']) {
        $preference['payer']['identification'] = [
            'type'   => $docInfo['type'],
            'number' => $docInfo['clean'],
        ];
    }

    return $preference;
}

/**
 * Busca o CPF/CNPJ no campo personalizado do cliente.
 */
function seixastec_mercadopago_getClientDocument(int $userId, int $fieldId): string
{
    if ($fieldId <= 0 || $userId <= 0) {
        return '';
    }

    try {
        $row = Capsule::table('tblcustomfieldsvalues')
            ->where('fieldid', $fieldId)
            ->where('relid', $userId)
            ->first();

        return $row ? trim((string) $row->value) : '';
    } catch (\Throwable $e) {
        return '';
    }
}

/**
 * Persiste/atualiza o preference_id na tabela auxiliar.
 */
function seixastec_mercadopago_storePreference(int $invoiceId, string $preferenceId, float $amount): void
{
    try {
        Capsule::table('mod_seixastec_mp_transactions')->updateOrInsert(
            ['invoice_id' => $invoiceId],
            [
                'preference_id' => $preferenceId,
                'amount'        => $amount,
                'status'        => 'pending',
                'updated_at'    => date('Y-m-d H:i:s'),
                'created_at'    => Capsule::raw('COALESCE(created_at, NOW())'),
            ]
        );
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) {
            logActivity('[Mercado Pago] Falha ao salvar preferência: ' . $e->getMessage());
        }
    }
}

/**
 * Monta o dropdown de campos personalizados de cliente.
 */
function seixastec_mercadopago_buildCustomFieldsDropdown(): array
{
    $options = ['' => '— Selecione um campo —'];

    try {
        $fields = Capsule::table('tblcustomfields')
            ->where('type', 'client')
            ->orderBy('sortorder')
            ->orderBy('fieldname')
            ->get(['id', 'fieldname']);

        foreach ($fields as $field) {
            $options[(string) $field->id] = $field->fieldname . ' (#' . $field->id . ')';
        }
    } catch (\Throwable $e) {
        // contexto de instalação
    }

    return $options;
}

/**
 * Renderiza o botão "Pagar com Mercado Pago".
 */
function seixastec_mercadopago_renderButton(string $url, float $finalAmount, float $originalAmount): string
{
    $extra = $finalAmount > $originalAmount
        ? sprintf(
            '<small style="display:block;margin-top:6px;color:#666;">Total com taxas: R$ %s</small>',
            number_format($finalAmount, 2, ',', '.')
        )
        : '';

    return sprintf(
        '<a href="%s" target="_blank" rel="noopener" class="btn btn-primary"
            style="background:#009ee3;border-color:#009ee3;padding:12px 30px;font-size:16px;">
            <i class="fas fa-credit-card"></i> Pagar com Mercado Pago
         </a>%s',
        htmlspecialchars($url, ENT_QUOTES, 'UTF-8'),
        $extra
    );
}

/**
 * Renderiza uma mensagem de erro no lugar do botão.
 */
function seixastec_mercadopago_renderError(string $title, string $message): string
{
    return sprintf(
        '<div class="alert alert-danger" style="margin:10px 0;">
            <strong>⚠️ %s</strong><br>
            <small>%s</small>
         </div>',
        htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
    );
}
