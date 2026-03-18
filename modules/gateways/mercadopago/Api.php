<?php

/**
 * Mercado Pago - WHMCS Gateway Module
 * API Helper Class
 *
 * Handles all HTTP communication with the Mercado Pago REST API.
 *
 * @author      Eduardo Seixas
 * @copyright   2026
 * @license     GPL-3.0
 */

declare(strict_types = 1)
;

namespace WHMCS\Module\Gateway\MercadoPago;

class Api
{
    private const BASE_URL = 'https://api.mercadopago.com';

    private string $accessToken;
    private array $lastError = [];

    public function __construct(string $accessToken)
    {
        $this->accessToken = $accessToken;
    }

    // -----------------------------------------------------------------------
    // Preferences (Checkout Pro)
    // -----------------------------------------------------------------------

    /**
     * Create a Checkout Pro preference.
     *
     * @param  array $data  Preference payload.
     * @return array|null   Decoded response or null on failure.
     */
    public function createPreference(array $data): ?array
    {
        return $this->post('/checkout/preferences', $data);
    }

    /**
     * Retrieve an existing preference.
     */
    public function getPreference(string $preferenceId): ?array
    {
        return $this->get("/checkout/preferences/{$preferenceId}");
    }

    // -----------------------------------------------------------------------
    // Payments
    // -----------------------------------------------------------------------

    /**
     * Retrieve a payment by its ID.
     */
    public function getPayment(string|int $paymentId): ?array
    {
        return $this->get("/v1/payments/{$paymentId}");
    }

    /**
     * Search payments by external_reference (invoice ID).
     *
     * @param  string $externalReference  The invoice ID.
     * @return array|null
     */
    public function searchPaymentByReference(string $externalReference): ?array
    {
        $query = http_build_query([
            'external_reference' => $externalReference,
            'sort' => 'date_created',
            'criteria' => 'desc',
        ]);
        return $this->get("/v1/payments/search?{$query}");
    }

    // -----------------------------------------------------------------------
    // Refunds
    // -----------------------------------------------------------------------

    /**
     * Issue a full or partial refund on a payment.
     *
     * @param  string|int  $paymentId  MP payment ID.
     * @param  float|null  $amount     Amount to refund; null = full refund.
     * @return array|null
     */
    public function refundPayment(string|int $paymentId, ?float $amount = null): ?array
    {
        $payload = [];
        if ($amount !== null) {
            $payload['amount'] = $amount;
        }
        return $this->post("/v1/payments/{$paymentId}/refunds", $payload);
    }

    // -----------------------------------------------------------------------
    // Merchant orders
    // -----------------------------------------------------------------------

    /**
     * Retrieve a merchant order by its ID.
     * Merchant orders aggregate one or more payments for the same preference.
     */
    public function getMerchantOrder(string|int $orderId): ?array
    {
        return $this->get("/merchant_orders/{$orderId}");
    }

    // -----------------------------------------------------------------------
    // Low-level HTTP helpers
    // -----------------------------------------------------------------------

    protected function get(string $endpoint): ?array
    {
        return $this->request('GET', $endpoint, []);
    }

    protected function post(string $endpoint, array $data): ?array
    {
        return $this->request('POST', $endpoint, $data);
    }

    /**
     * Execute an HTTP request against the MP API.
     *
     * @param  string $method    HTTP verb (GET | POST | PUT | PATCH).
     * @param  string $endpoint  API path (starts with /).
     * @param  array  $data      Body payload (for non-GET requests).
     * @return array|null        Decoded JSON response, or null on failure.
     */
    private function request(string $method, string $endpoint, array $data): ?array
    {
        $this->lastError = [];

        $url = self::BASE_URL . $endpoint;

        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json',
            'X-Idempotency-Key: ' . $this->idempotencyKey($endpoint, $data),
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if (!empty($data)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $this->lastError = ['curl_error' => $curlError];
            logModuleCall(
                'mercadopago',
                'API ' . $method . ' ' . $endpoint,
                $data,
                'cURL Error: ' . $curlError,
                null
            );
            return null;
        }

        $decoded = json_decode($response, true);

        logModuleCall(
            'mercadopago',
            'API ' . $method . ' ' . $endpoint,
            $data,
            $response,
            $decoded ?? [],
        [$this->accessToken] // masks the token in logs
        );

        if ($httpCode >= 400) {
            $this->lastError = [
                'http_code' => $httpCode,
                'response' => $decoded,
            ];
            return null;
        }

        return $decoded;
    }

    /**
     * Generate a deterministic idempotency key so that retrying the same
     * operation does not create duplicate records on the MP side.
     */
    private function idempotencyKey(string $endpoint, array $data): string
    {
        return hash('sha256', $endpoint . json_encode($data));
    }

    /**
     * Return details of the last failed request.
     */
    public function getLastError(): array
    {
        return $this->lastError;
    }
}
