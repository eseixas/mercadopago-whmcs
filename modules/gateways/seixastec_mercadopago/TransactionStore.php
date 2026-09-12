<?php

declare(strict_types=1);

namespace WHMCS\Module\Gateway\SeixastecMercadoPago;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

final class TransactionStore
{
    /**
     * Persiste (ou atualiza) a transação local, incluindo dados de PIX/boleto
     * usados pelos hooks de PDF, e-mail e área do cliente.
     *
     * @param array<string, mixed> $payment Resposta da API do Mercado Pago
     */
    public static function save(int $invoiceId, array $payment, string $method, float $amount): void
    {
        $paymentId = (string) ($payment['id'] ?? '');
        if ($invoiceId <= 0 || $paymentId === '') {
            return;
        }

        try {
            if (!Capsule::schema()->hasTable(SEIXASTEC_MP_TABLE)) {
                return;
            }

            $poi = is_array($payment['point_of_interaction']['transaction_data'] ?? null)
                ? $payment['point_of_interaction']['transaction_data']
                : [];
            $td = is_array($payment['transaction_details'] ?? null)
                ? $payment['transaction_details']
                : [];

            $status = (string) ($payment['status'] ?? 'pending');
            $now = date('Y-m-d H:i:s');

            $row = [
                'invoice_id'     => $invoiceId,
                'payment_id'     => $paymentId,
                'status'         => $status,
                'method'         => $method !== '' ? $method : (string) ($payment['payment_method_id'] ?? ''),
                'amount'         => $amount,
                'pix_qr_base64'  => (string) ($poi['qr_code_base64'] ?? ''),
                'pix_copia_cola' => (string) ($poi['qr_code'] ?? ''),
                'boleto_url'     => (string) ($td['external_resource_url'] ?? ''),
                'boleto_linha'   => (string) ($payment['barcode']['content'] ?? ''),
                'updated_at'     => $now,
            ];

            if ($status === 'approved') {
                $row['paid_at'] = $now;
            }

            Capsule::table(SEIXASTEC_MP_TABLE)->updateOrInsert(
                ['payment_id' => $paymentId],
                $row
            );
        } catch (\Throwable $e) {
            // auditoria local não impede o pagamento
        }
    }
}
