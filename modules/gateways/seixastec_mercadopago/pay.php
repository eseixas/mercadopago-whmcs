<?php
/**
 * Mercado Pago - Página de Checkout
 *
 * Gera preferência de pagamento e renderiza o Payment Brick
 * com suporte a PIX, Cartão de Crédito e Boleto.
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
// 1. Validação inicial
// ---------------------------------------------------------------------------
$invoiceId = (int) ($_GET['invoiceid'] ?? 0);
if ($invoiceId <= 0) {
    http_response_code(400);
    exit('Fatura inválida.');
}

// Carrega configuração do gateway
$gateway = getGatewayVariables('seixastec_mercadopago');
if (empty($gateway['type'])) {
    http_response_code(503);
    exit('Gateway Mercado Pago não está ativo.');
}

$sandboxMode = ($gateway['sandboxMode'] ?? '') === 'on';
$accessToken = trim((string) ($gateway['accessToken'] ?? ''));
$publicKey = trim((string) ($gateway['publicKey'] ?? ''));

if (empty($accessToken) || empty($publicKey)) {
    http_response_code(503);
    exit('Credenciais do Mercado Pago não configuradas.');
}

// ---------------------------------------------------------------------------
// 2. Autenticação do cliente
// ---------------------------------------------------------------------------
if (!isset($_SESSION['uid']) || (int) $_SESSION['uid'] <= 0) {
    header('Location: ' . rtrim($gateway['systemurl'], '/') . '/clientarea.php');
    exit;
}
$clientId = (int) $_SESSION['uid'];

// ---------------------------------------------------------------------------
// 3. Carrega dados da fatura
// ---------------------------------------------------------------------------
try {
    $invoice = Capsule::table('tblinvoices')
        ->where('id', $invoiceId)
        ->where('userid', $clientId)
        ->first();

    if (!$invoice) {
        http_response_code(404);
        exit('Fatura não encontrada ou acesso negado.');
    }

    if ($invoice->status === 'Paid') {
        header('Location: ' . rtrim($gateway['systemurl'], '/') . '/viewinvoice.php?id=' . $invoiceId);
        exit;
    }

    if (in_array($invoice->status, ['Cancelled', 'Refunded'], true)) {
        exit('Esta fatura não pode ser paga (status: ' . htmlspecialchars($invoice->status) . ').');
    }

    $client = Capsule::table('tblclients')->where('id', $clientId)->first();
    if (!$client) {
        http_response_code(404);
        exit('Cliente não encontrado.');
    }
} catch (Throwable $e) {
    logModule('seixastec_mercadopago', 'pay.php - DB Error', $e->getMessage());
    http_response_code(500);
    exit('Erro ao carregar dados da fatura.');
}

// ---------------------------------------------------------------------------
// 4. Calcula valor (com taxa adicional, se houver)
// ---------------------------------------------------------------------------
$amount = (float) $invoice->total;
$taxa = (float) ($gateway['feePercent'] ?? 0);
if ($taxa > 0) {
    $amount = round($amount * (1 + $taxa / 100), 2);
}

if ($amount <= 0) {
    exit('Valor da fatura inválido.');
}

// ---------------------------------------------------------------------------
// 5. Define métodos de pagamento exibidos
// ---------------------------------------------------------------------------
$methods = $gateway['paymentMethods'] ?? 'all';
$paymentMethodsConfig = [
    'creditCard'    => 'all',
    'debitCard'     => 'all',
    'ticket'        => 'all',
    'bankTransfer'  => 'all', // PIX
    'atm'           => 'all',
    'maxInstallments' => (int) ($gateway['maxInstallments'] ?? 12),
];

switch ($methods) {
    case 'pix':
        $paymentMethodsConfig['creditCard']   = 'none';
        $paymentMethodsConfig['debitCard']    = 'none';
        $paymentMethodsConfig['ticket']       = 'none';
        $paymentMethodsConfig['atm']          = 'none';
        break;
    case 'card':
        $paymentMethodsConfig['ticket']       = 'none';
        $paymentMethodsConfig['bankTransfer'] = 'none';
        $paymentMethodsConfig['atm']          = 'none';
        break;
    case 'ticket':
        $paymentMethodsConfig['creditCard']   = 'none';
        $paymentMethodsConfig['debitCard']    = 'none';
        $paymentMethodsConfig['bankTransfer'] = 'none';
        $paymentMethodsConfig['atm']          = 'none';
        break;
    case 'pix_card':
        $paymentMethodsConfig['ticket']       = 'none';
        $paymentMethodsConfig['atm']          = 'none';
        break;
}

// ---------------------------------------------------------------------------
// 6. URLs de retorno e webhook
// ---------------------------------------------------------------------------
$systemUrl  = rtrim($gateway['systemurl'], '/');
$returnUrl  = $systemUrl . '/viewinvoice.php?id=' . $invoiceId;
$webhookUrl = $systemUrl . '/modules/gateways/callback/seixastec_mercadopago.php';

// ---------------------------------------------------------------------------
// 7. Dados do pagador
// ---------------------------------------------------------------------------
$payerName  = trim(($client->firstname ?? '') . ' ' . ($client->lastname ?? ''));
$payerEmail = $client->email ?? '';

// CPF/CNPJ via custom field (ajuste o ID conforme seu WHMCS)
$payerDocument = '';
try {
    $cfRow = Capsule::table('tblcustomfieldsvalues as v')
        ->join('tblcustomfields as f', 'f.id', '=', 'v.fieldid')
        ->where('v.relid', $clientId)
        ->where('f.type', 'client')
        ->whereIn('f.fieldname', ['CPF', 'CNPJ', 'CPF/CNPJ', 'Documento'])
        ->value('v.value');
    $payerDocument = preg_replace('/\D/', '', (string) $cfRow);
} catch (Throwable $e) {
    // Silencioso - campo opcional
}

// ---------------------------------------------------------------------------
// 8. Renderiza HTML
// ---------------------------------------------------------------------------
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Pagamento Fatura #<?= htmlspecialchars((string) $invoiceId) ?> - Mercado Pago</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        body {
            background: #f5f5f5;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            padding: 20px 0;
        }
        .checkout-card {
            max-width: 720px;
            margin: 20px auto;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        .checkout-header {
            background: linear-gradient(135deg, #009ee3, #00b1ea);
            color: #fff;
            padding: 24px;
            text-align: center;
        }
        .checkout-header h1 {
            font-size: 22px;
            margin: 0;
            font-weight: 600;
        }
        .checkout-header p {
            margin: 8px 0 0;
            opacity: 0.9;
            font-size: 14px;
        }
        .invoice-summary {
            padding: 20px 24px;
            border-bottom: 1px solid #eee;
            background: #fafbfc;
        }
        .invoice-summary .row {
            margin: 0;
        }
        .invoice-summary .label {
            color: #666;
            font-size: 13px;
        }
        .invoice-summary .value {
            color: #333;
            font-weight: 600;
            font-size: 15px;
        }
        .amount-total {
            font-size: 28px !important;
            color: #009ee3 !important;
        }
        #paymentBrick_container {
            padding: 24px;
            min-height: 400px;
        }
        .loading-box {
            text-align: center;
            padding: 60px 20px;
            color: #666;
        }
        .loading-box .spinner-border {
            color: #009ee3;
            width: 3rem;
            height: 3rem;
        }
        .footer-info {
            padding: 16px 24px;
            background: #fafbfc;
            border-top: 1px solid #eee;
            text-align: center;
            font-size: 12px;
            color: #888;
        }
        .alert-box {
            margin: 20px 24px;
        }
    </style>
</head>
<body>

<div class="checkout-card">
    <div class="checkout-header">
        <h1><i class="fas fa-lock"></i> Pagamento Seguro</h1>
        <p>Fatura #<?= htmlspecialchars((string) $invoiceId) ?> • Processado por Mercado Pago</p>
    </div>

    <div class="invoice-summary">
        <div class="row">
            <div class="col-6">
                <div class="label">Cliente</div>
                <div class="value"><?= htmlspecialchars($payerName) ?></div>
            </div>
            <div class="col-6 text-end">
                <div class="label">Total a pagar</div>
                <div class="value amount-total">
                    R$ <?= number_format($amount, 2, ',', '.') ?>
                </div>
            </div>
        </div>
        <?php if ($taxa > 0): ?>
            <div class="row mt-2">
                <div class="col-12">
                    <small class="text-muted">
                        <i class="fas fa-info-circle"></i>
                        Inclui taxa adicional de <?= number_format($taxa, 2, ',', '.') ?>%
                    </small>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($sandboxMode): ?>
        <div class="alert alert-warning alert-box mb-0">
            <i class="fas fa-flask"></i>
            <strong>Modo Sandbox ativo.</strong> Use cartões de teste do Mercado Pago.
        </div>
    <?php endif; ?>

    <div id="paymentBrick_container">
        <div class="loading-box">
            <div class="spinner-border" role="status"></div>
            <p class="mt-3">Carregando opções de pagamento...</p>
        </div>
    </div>

    <div class="footer-info">
        <i class="fas fa-shield-alt"></i>
        Seus dados estão protegidos com criptografia SSL.
        <br>
        <a href="<?= htmlspecialchars($returnUrl) ?>" class="text-muted">
            <i class="fas fa-arrow-left"></i> Voltar para a fatura
        </a>
    </div>
</div>

<script src="https://sdk.mercadopago.com/js/v2"></script>
<script>
(function() {
    'use strict';

    const mp = new MercadoPago('<?= htmlspecialchars($publicKey, ENT_QUOTES) ?>', {
        locale: 'pt-BR'
    });

    const bricksBuilder = mp.bricks();

    const renderPaymentBrick = async () => {
        const settings = {
            initialization: {
                amount: <?= json_encode($amount) ?>,
                payer: {
                    firstName: <?= json_encode($client->firstname ?? '') ?>,
                    lastName:  <?= json_encode($client->lastname ?? '') ?>,
                    email:     <?= json_encode($payerEmail) ?>
                }
            },
            customization: {
                visual: {
                    style: { theme: 'default' }
                },
                paymentMethods: {
                    creditCard:    '<?= $paymentMethodsConfig['creditCard'] ?>',
                    debitCard:     '<?= $paymentMethodsConfig['debitCard'] ?>',
                    ticket:        '<?= $paymentMethodsConfig['ticket'] ?>',
                    bankTransfer:  '<?= $paymentMethodsConfig['bankTransfer'] ?>',
                    atm:           '<?= $paymentMethodsConfig['atm'] ?>',
                    maxInstallments: <?= (int) $paymentMethodsConfig['maxInstallments'] ?>
                }
            },
            callbacks: {
                onReady: () => {
                    const loader = document.querySelector('.loading-box');
                    if (loader) loader.style.display = 'none';
                },
                onSubmit: ({ selectedPaymentMethod, formData }) => {
                    return new Promise((resolve, reject) => {
                        fetch('process.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: JSON.stringify({
                                invoice_id: <?= (int) $invoiceId ?>,
                                payment_method: selectedPaymentMethod,
                                form_data: formData
                            })
                        })
                        .then(response => response.json())
                        .then(result => {
                            if (result.success) {
                                if (result.redirect) {
                                    window.location.href = result.redirect;
                                } else {
                                    window.location.href = '<?= htmlspecialchars($returnUrl) ?>';
                                }
                                resolve();
                            } else {
                                alert('Erro: ' + (result.message || 'Falha ao processar pagamento.'));
                                reject();
                            }
                        })
                        .catch(error => {
                            console.error('Erro:', error);
                            alert('Erro de comunicação. Tente novamente.');
                            reject();
                        });
                    });
                },
                onError: (error) => {
                    console.error('Brick Error:', error);
                }
            }
        };

        window.paymentBrickController = await bricksBuilder.create(
            'payment',
            'paymentBrick_container',
            settings
        );
    };

    renderPaymentBrick();
})();
</script>

</body>
</html>
