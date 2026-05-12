<?php
/**
 * Mercado Pago - Hook de Injeção PIX/Boleto em PDF, E-mails e Área do Cliente
 *
 * Recupera dados de pagamento armazenados em `mod_seixastec_mp_transactions`
 * e os injeta automaticamente em três pontos:
 *
 *   1. PDF da fatura (InvoicePdfGeneration)
 *      → Renderiza QR Code PIX + linha digitável de boleto no rodapé.
 *
 *   2. E-mails de fatura (EmailPreSend)
 *      → Disponibiliza variáveis Smarty para uso em templates.
 *
 *   3. Área do cliente (ClientAreaPageViewInvoice)
 *      → Injeta bloco HTML com QR Code, botão "Copiar" e link do boleto.
 *
 * Variáveis Smarty disponibilizadas:
 *   {$mp_pix_qr_base64}     QR Code em base64 (apenas a string, sem prefixo)
 *   {$mp_pix_copia_cola}    Código PIX Copia e Cola
 *   {$mp_boleto_url}        URL do boleto no MP
 *   {$mp_boleto_linha}      Linha digitável do boleto
 *   {$mp_payment_method}    Método (pix|bolbradesco|credit_card|...)
 *   {$mp_status}            Status atual
 *   {$mp_payment_id}        ID do pagamento no MP
 *   {$mp_payment_box}       HTML pronto para inserir (apenas no ClientArea)
 *   {$mp_has_data}          Boolean (apenas no ClientArea)
 *
 * Compatível com: WHMCS 8.x / 9.x | PHP 8.1+
 *
 * Autor: Eduardo Seixas
 * Versão: 1.0.0
 * Atualizado: 2026-05-11
 * Licença: GPL-3.0
 */

declare(strict_types=1);

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

// =======================================================================
// HOOK 1: PDF DA FATURA
// =======================================================================

add_hook('InvoicePdfGeneration', 1, function (array $vars): void {
    $invoiceId = (int) ($vars['invoiceid'] ?? 0);
    if ($invoiceId <= 0) {
        return;
    }

    $tx = seixastec_mp_getTransaction($invoiceId);
    if ($tx === null) {
        return;
    }

    // Não injeta em faturas já pagas
    if (($tx->status ?? '') === 'approved') {
        return;
    }

    /** @var \WHMCS\TCPDF\InvoicePdf|object|null $pdf */
    $pdf = $vars['pdf'] ?? null;
    if (!$pdf || !is_object($pdf)) {
        return;
    }

    try {
        $hasPix    = !empty($tx->pix_qr_base64) && !empty($tx->pix_copia_cola);
        $hasBoleto = !empty($tx->boleto_linha) || !empty($tx->boleto_url);

        if (!$hasPix && !$hasBoleto) {
            return;
        }

        $pdf->Ln(8);
        $pdf->SetFont('freesans', 'B', 11);
        $pdf->SetTextColor(0, 158, 227); // azul MP
        $pdf->Cell(0, 6, 'Mercado Pago - Dados de Pagamento', 0, 1, 'L');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(2);

        if ($hasPix) {
            seixastec_mp_addPixToPdf($pdf, $tx);
        }

        if ($hasBoleto) {
            seixastec_mp_addBoletoToPdf($pdf, $tx);
        }

    } catch (\Throwable $e) {
        if (function_exists('logActivity')) {
            logActivity('[Mercado Pago] Erro renderizar PDF: ' . $e->getMessage());
        }
    }
});

// =======================================================================
// HOOK 2: E-MAILS DE FATURA
// =======================================================================

add_hook('EmailPreSend', 1, function (array $vars): array {
    $messageType = (string) ($vars['messagename'] ?? '');
    $relatedId   = (int) ($vars['relid'] ?? 0);

    $invoiceEmails = [
        'Invoice Created',
        'Invoice Payment Reminder',
        'First Payment Reminder',
        'Second Payment Reminder',
        'Third Payment Reminder',
        'Invoice Payment Confirmation',
        'Credit Card Payment Failed',
    ];

    if (!in_array($messageType, $invoiceEmails, true) || $relatedId <= 0) {
        return [];
    }

    $tx = seixastec_mp_getTransaction($relatedId);
    if ($tx === null) {
        return [];
    }

    return seixastec_mp_buildSmartyVars($tx);
});

// =======================================================================
// HOOK 3: ÁREA DO CLIENTE — VISUALIZAÇÃO DA FATURA
// =======================================================================

add_hook('ClientAreaPageViewInvoice', 1, function (array $vars): array {
    $invoiceId = (int) ($vars['invoiceid'] ?? 0);
    if ($invoiceId <= 0) {
        return [];
    }

    $tx = seixastec_mp_getTransaction($invoiceId);
    if ($tx === null) {
        return [];
    }

    // Só exibe bloco interativo em faturas pendentes
    $status = strtolower((string) ($vars['status'] ?? ''));
    if (!in_array($status, ['unpaid', 'overdue', 'draft'], true)) {
        return [];
    }

    return seixastec_mp_buildSmartyVars($tx) + [
        'mp_has_data'    => true,
        'mp_payment_box' => seixastec_mp_renderClientBlock($tx),
    ];
});

// =======================================================================
// FUNÇÕES AUXILIARES
// =======================================================================

/**
 * Busca a transação MP vinculada à fatura.
 *
 * @return object|null
 */
function seixastec_mp_getTransaction(int $invoiceId): ?object
{
    try {
        if (!Capsule::schema()->hasTable('mod_seixastec_mp_transactions')) {
            return null;
        }

        $row = Capsule::table('mod_seixastec_mp_transactions')
            ->where('invoice_id', $invoiceId)
            ->first();

        return $row ?: null;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Monta o array de variáveis Smarty.
 */
function seixastec_mp_buildSmartyVars(object $tx): array
{
    return [
        'mp_pix_qr_base64'  => (string) ($tx->pix_qr_base64 ?? ''),
        'mp_pix_copia_cola' => (string) ($tx->pix_copia_cola ?? ''),
        'mp_boleto_url'     => (string) ($tx->boleto_url ?? ''),
        'mp_boleto_linha'   => (string) ($tx->boleto_linha ?? ''),
        'mp_payment_method' => (string) ($tx->method ?? ''),
        'mp_status'         => (string) ($tx->status ?? ''),
        'mp_payment_id'     => (string) ($tx->payment_id ?? ''),
    ];
}

/**
 * Renderiza o bloco HTML para a área do cliente.
 */
function seixastec_mp_renderClientBlock(object $tx): string
{
    $html = '<div class="mp-payment-box" style="border:1px solid #009ee3;border-radius:8px;padding:20px;margin:20px 0;background:#f9fcff;">';
    $html .= '<h4 style="color:#009ee3;margin:0 0 15px;border-bottom:2px solid #009ee3;padding-bottom:8px;">'
           . '<i class="fas fa-bolt"></i> Pagamento via Mercado Pago</h4>';

    // ─── PIX ───
    if (!empty($tx->pix_qr_base64) && !empty($tx->pix_copia_cola)) {
        $qrBase64  = htmlspecialchars((string) $tx->pix_qr_base64, ENT_QUOTES, 'UTF-8');
        $copiaCola = htmlspecialchars((string) $tx->pix_copia_cola, ENT_QUOTES, 'UTF-8');

        $html .= <<<HTML
<div class="mp-pix-section" style="margin-bottom:20px;">
    <h5 style="margin:0 0 10px;color:#333;">💚 Pagar via PIX</h5>
    <div style="display:flex;flex-wrap:wrap;gap:20px;align-items:flex-start;">
        <div>
            <img src="data:image/png;base64,{$qrBase64}" alt="QR Code PIX"
                 style="width:200px;height:200px;border:1px solid #ddd;border-radius:4px;background:#fff;padding:8px;">
        </div>
        <div style="flex:1;min-width:250px;">
            <p style="margin:0 0 8px;"><strong>Escaneie o QR Code</strong> com o app do seu banco<br>ou copie o código abaixo:</p>
            <textarea id="mp-pix-code" readonly rows="3"
                style="width:100%;padding:8px;font-family:monospace;font-size:11px;border:1px solid #ccc;border-radius:4px;resize:none;">{$copiaCola}</textarea>
            <button type="button" onclick="mpCopyPixCode(this)" class="btn btn-sm btn-success" style="margin-top:8px;">
                <i class="fas fa-copy"></i> Copiar código
            </button>
            <p style="margin-top:10px;font-size:12px;color:#666;">⚡ Confirmação automática em poucos segundos.</p>
        </div>
    </div>
</div>
<script>
function mpCopyPixCode(btn) {
    var el = document.getElementById('mp-pix-code');
    el.select(); el.setSelectionRange(0, 99999);
    var done = false;
    try {
        if (navigator.clipboard) {
            navigator.clipboard.writeText(el.value);
            done = true;
        } else {
            done = document.execCommand('copy');
        }
    } catch(e) {}
    if (done) {
        var orig = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check"></i> Copiado!';
        setTimeout(function(){ btn.innerHTML = orig; }, 2000);
    }
}
</script>
HTML;
    }

    // ─── BOLETO ───
    if (!empty($tx->boleto_url) || !empty($tx->boleto_linha)) {
        $html .= '<div class="mp-boleto-section" style="margin-top:20px;padding-top:20px;border-top:1px solid #e0e0e0;">';
        $html .= '<h5 style="margin:0 0 10px;color:#333;">📄 Pagar via Boleto Bancário</h5>';

        if (!empty($tx->boleto_linha)) {
            $linha = htmlspecialchars((string) $tx->boleto_linha, ENT_QUOTES, 'UTF-8');
            $html .= '<p style="margin:5px 0;font-size:13px;color:#555;">Linha digitável:</p>';
            $html .= '<input type="text" value="' . $linha . '" readonly onclick="this.select()"'
                  . ' style="width:100%;padding:8px;font-family:monospace;font-size:12px;font-weight:bold;border:1px solid #ccc;border-radius:4px;">';
        }

        if (!empty($tx->boleto_url)) {
            $url = htmlspecialchars((string) $tx->boleto_url, ENT_QUOTES, 'UTF-8');
            $html .= '<a href="' . $url . '" target="_blank" rel="noopener" class="btn btn-primary"'
                  . ' style="margin-top:10px;background:#009ee3;border-color:#009ee3;">'
                  . '<i class="fas fa-download"></i> Baixar Boleto</a>';
        }

        $html .= '<small style="display:block;margin-top:8px;color:#666;">⏱ Compensação bancária em até 3 dias úteis.</small>';
        $html .= '</div>';
    }

    $html .= '</div>';
    return $html;
}

/**
 * Renderiza seção PIX no PDF da fatura.
 */
function seixastec_mp_addPixToPdf($pdf, object $tx): void
{
    try {
        $pdf->SetFont('freesans', 'B', 10);
        $pdf->Cell(0, 5, 'PIX', 0, 1, 'L');

        $pdf->SetFont('freesans', '', 8);
        $pdf->MultiCell(0, 4, 'Escaneie o QR Code abaixo ou copie o código PIX:', 0, 'L');
        $pdf->Ln(2);

        // QR Code
        $imgBinary = base64_decode((string) $tx->pix_qr_base64, true);
        if ($imgBinary !== false) {
            $tmpQr = tempnam(sys_get_temp_dir(), 'mp_qr_') . '.png';
            if ($tmpQr !== false && file_put_contents($tmpQr, $imgBinary) !== false) {
                $pdf->Image($tmpQr, $pdf->GetX(), $pdf->GetY(), 35, 35, 'PNG');
                $pdf->Ln(38);
                @unlink($tmpQr);
            }
        }

        $pdf->SetFont('freemono', '', 7);
        $pdf->MultiCell(0, 3, (string) $tx->pix_copia_cola, 0, 'L');
        $pdf->Ln(3);
    } catch (\Throwable $e) {
        // não bloqueia a geração do PDF
    }
}

/**
 * Renderiza seção Boleto no PDF da fatura.
 */
function seixastec_mp_addBoletoToPdf($pdf, object $tx): void
{
    try {
        $pdf->SetFont('freesans', 'B', 10);
        $pdf->Cell(0, 5, 'Boleto Bancario', 0, 1, 'L');

        if (!empty($tx->boleto_linha)) {
            $pdf->SetFont('freesans', '', 8);
            $pdf->Cell(0, 4, 'Linha digitavel:', 0, 1, 'L');

            $pdf->SetFont('freemono', 'B', 9);
            $pdf->Cell(0, 5, (string) $tx->boleto_linha, 0, 1, 'L');
        }

        if (!empty($tx->boleto_url)) {
            $pdf->SetFont('freesans', '', 8);
            $pdf->SetTextColor(0, 102, 204);
            $pdf->Cell(0, 4, (string) $tx->boleto_url, 0, 1, 'L', false, (string) $tx->boleto_url);
            $pdf->SetTextColor(0, 0, 0);
        }
    } catch (\Throwable $e) {
        // não bloqueia a geração do PDF
    }
}
