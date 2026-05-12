<?php
/**
 * Mercado Pago - Cliente HTTP da API v1
 *
 * Wrapper completo para a API REST do Mercado Pago.
 *
 * Endpoints suportados:
 *   - POST   /checkout/preferences           Cria preferência (Checkout Pro)
 *   - GET    /checkout/preferences/{id}      Consulta preferência
 *   - POST   /v1/payments                    Cria pagamento direto (PIX/Boleto)
 *   - GET    /v1/payments/{id}               Consulta pagamento
 *   - GET    /v1/payments/search             Busca pagamentos (por external_reference)
 *   - POST   /v1/payments/{id}/refunds       Reembolso total/parcial
 *   - GET    /merchant_orders/{id}           Consulta merchant order
 *   - GET    /v1/payment_methods             Lista métodos de pagamento
 *
 * Recursos:
 *   - Retry automático com backoff exponencial (3 tentativas)
 *   - Idempotency-Key automático em operações POST
 *   - Timeout configurável (padrão: 30s)
 *   - SSL verification estrita (CA bundle do sistema)
 *   - Mascaramento de credenciais em logs
 *   - Tratamento estruturado de erros (last error)
 *
 * Compatível com: WHMCS 9.x | PHP 8.3 | Mercado Pago API v1
 *
 * Autor: Eduardo Seixas
 * Atualizado: 2026
 * Licença: GPL-3.0
 */

declare(strict_types=1);

namespace WHMCS\Module\Gateway\SeixastecMercadoPago;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class Api
{
    /** Endpoint base da API do Mercado Pago. */
    private const BASE_URL = 'https://api.mercadopago.com';

    /** User-Agent identificando o integrador. */
    private const USER_AGENT = 'WHMCS-MercadoPago-Seixastec/1.6.0 (+https://github.com/eduardoseixas)';

    /** Tempo máximo de espera por resposta (segundos). */
    private const TIMEOUT = 30;

    /** Tempo máximo para estabelecer conexão (segundos). */
    private const CONNECT_TIMEOUT = 10;

    /** Número máximo de tentativas em caso de erro de rede ou 5xx. */
    private const MAX_RETRIES = 3;

    /** Base do backoff exponencial em milissegundos. */
    private const RETRY_BACKOFF_MS = 500;

    /** Códigos HTTP que disparam retry automático. */
    private const RETRYABLE_STATUS = [408, 429, 500, 502, 503, 504];

    private string $accessToken;
    private bool $debugMode;
    private ?string $lastError = null;
    private ?int $lastHttpCode = null;
    private ?array $lastResponse = null;

    public function __construct(string $accessToken, bool $debugMode = false)
    {
        if ($accessToken === '') {
            throw new \InvalidArgumentException('Access Token é obrigatório.');
        }
        $this->accessToken = $accessToken;
        $this->debugMode   = $debugMode;
    }

    // =======================================================================
    // PREFERÊNCIAS (Checkout Pro)
    // =======================================================================

    /**
     * Cria uma preferência de pagamento (Checkout Pro).
     *
     * @param array $preference Estrutura da preferência conforme docs do MP.
     * @return array|null Resposta decodificada ou null em caso de falha.
     */
    public function createPreference(array $preference): ?array
    {
        return $this->request('POST', '/checkout/preferences', $preference, true);
    }

    /**
     * Consulta uma preferência pelo ID.
     */
    public function getPreference(string $preferenceId): ?array
    {
        return $this->request('GET', '/checkout/preferences/' . urlencode($preferenceId));
    }

    // =======================================================================
    // PAGAMENTOS
    // =======================================================================

    /**
     * Cria pagamento direto (PIX, Boleto, Cartão tokenizado).
     */
    public function createPayment(array $payment): ?array
    {
        return $this->request('POST', '/v1/payments', $payment, true);
    }

    /**
     * Consulta um pagamento pelo ID.
     */
    public function getPayment(string $paymentId): ?array
    {
        return $this->request('GET', '/v1/payments/' . urlencode($paymentId));
    }

    /**
     * Busca pagamentos por external_reference (= ID da fatura WHMCS).
     *
     * @return array|null Estrutura `{ paging: {...}, results: [...] }`
     */
    public function searchPaymentsByExternalReference(string $externalReference, int $limit = 20): ?array
    {
        $query = http_build_query([
            'external_reference' => $externalReference,
            'sort'               => 'date_created',
            'criteria'           => 'desc',
            'limit'              => max(1, min($limit, 50)),
        ]);

        return $this->request('GET', '/v1/payments/search?' . $query);
    }

    /**
     * Busca pagamentos com filtros customizados.
     *
     * @param array $filters Pares chave/valor (ex.: ['status' => 'approved'])
     */
    public function searchPayments(array $filters, int $limit = 20): ?array
    {
        $filters['limit'] = max(1, min($limit, 50));
        return $this->request('GET', '/v1/payments/search?' . http_build_query($filters));
    }

    // =======================================================================
    // REEMBOLSOS
    // =======================================================================

    /**
     * Reembolsa um pagamento (total ou parcial).
     *
     * @param string $paymentId ID do pagamento.
     * @param float|null $amount Valor a reembolsar; null = reembolso total.
     */
    public function refundPayment(string $paymentId, ?float $amount = null): ?array
    {
        $body = [];
        if ($amount !== null && $amount > 0) {
            $body['amount'] = round($amount, 2);
        }

        return $this->request(
            'POST',
            '/v1/payments/' . urlencode($paymentId) . '/refunds',
            $body,
            true
        );
    }

    /**
     * Lista todos os reembolsos de um pagamento.
     */
    public function listRefunds(string $paymentId): ?array
    {
        return $this->request('GET', '/v1/payments/' . urlencode($paymentId) . '/refunds');
    }

    /**
     * Consulta um reembolso específico.
     */
    public function getRefund(string $paymentId, string $refundId): ?array
    {
        return $this->request(
            'GET',
            '/v1/payments/' . urlencode($paymentId) . '/refunds/' . urlencode($refundId)
        );
    }

    // =======================================================================
    // MERCHANT ORDERS
    // =======================================================================

    /**
     * Consulta uma merchant order (agrega múltiplos pagamentos).
     */
    public function getMerchantOrder(string $orderId): ?array
    {
        return $this->request('GET', '/merchant_orders/' . urlencode($orderId));
    }

    // =======================================================================
    // MÉTODOS DE PAGAMENTO / UTILITÁRIOS
    // =======================================================================

    /**
     * Lista todos os métodos de pagamento disponíveis para a conta.
     */
    public function getPaymentMethods(): ?array
    {
        return $this->request('GET', '/v1/payment_methods');
    }

    /**
     * Testa a validade do Access Token (chama endpoint leve).
     */
    public function testCredentials(): bool
    {
        $result = $this->getPaymentMethods();
        return $result !== null && is_array($result) && !empty($result);
    }

    // =======================================================================
    // ESTADO / ERROS
    // =======================================================================

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getLastHttpCode(): ?int
    {
        return $this->lastHttpCode;
    }

    public function getLastResponse(): ?array
    {
        return $this->lastResponse;
    }

    // =======================================================================
    // CORE HTTP
    // =======================================================================

    /**
     * Executa requisição HTTP com retry e backoff exponencial.
     *
     * @param string $method POST, GET, PUT, DELETE
     * @param string $path Caminho relativo (começa com `/`)
     * @param array|null $body Corpo da requisição (apenas POST/PUT)
     * @param bool $idempotent Adiciona X-Idempotency-Key
     * @return array|null Resposta decodificada ou null em erro
     */
    private function request(
        string $method,
        string $path,
        ?array $body = null,
        bool $idempotent = false
    ): ?array {
        $this->lastError    = null;
        $this->lastHttpCode = null;
        $this->lastResponse = null;

        $url     = self::BASE_URL . $path;
        $jsonBody = null;

        if ($body !== null && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $jsonBody = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($jsonBody === false) {
                $this->lastError = 'Falha ao serializar body: ' . json_last_error_msg();
                $this->logCall($method, $path, $body, $this->lastError);
                return null;
            }
        }

        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: ' . self::USER_AGENT,
            'X-Product-Id: bc32bpgtrpp001kbgpt0',
        ];

        if ($idempotent) {
            $headers[] = 'X-Idempotency-Key: ' . $this->generateIdempotencyKey($method, $path, $jsonBody);
        }

        $attempt = 0;
        $lastResponseRaw = '';

        while ($attempt < self::MAX_RETRIES) {
            $attempt++;
            $ch = curl_init();

            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_ENCODING       => 'gzip, deflate',
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            ]);

            if ($jsonBody !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
            }

            $rawResponse = curl_exec($ch);
            $httpCode    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErrno   = curl_errno($ch);
            $curlError   = curl_error($ch);
            curl_close($ch);

            $this->lastHttpCode = $httpCode;
            $lastResponseRaw    = is_string($rawResponse) ? $rawResponse : '';

            // ---- Erro de rede / cURL ----
            if ($rawResponse === false || $curlErrno !== 0) {
                $this->lastError = "cURL #{$curlErrno}: {$curlError}";

                if ($attempt < self::MAX_RETRIES) {
                    $this->sleepBackoff($attempt);
                    continue;
                }

                $this->logCall($method, $path, $body, $this->lastError);
                return null;
            }

            // ---- HTTP retryable (5xx, 429, etc.) ----
            if (in_array($httpCode, self::RETRYABLE_STATUS, true) && $attempt < self::MAX_RETRIES) {
                $this->sleepBackoff($attempt);
                continue;
            }

            // ---- Decodifica resposta ----
            $decoded = json_decode($lastResponseRaw, true);
            if (!is_array($decoded)) {
                $decoded = ['raw' => $lastResponseRaw];
            }
            $this->lastResponse = $decoded;

            // ---- Sucesso (2xx) ----
            if ($httpCode >= 200 && $httpCode < 300) {
                $this->logCall($method, $path, $body, [
                    'http_code' => $httpCode,
                    'response'  => $decoded,
                ]);
                return $decoded;
            }

            // ---- Erro HTTP definitivo (4xx não-retryable) ----
            $this->lastError = $this->extractErrorMessage($decoded, $httpCode);
            $this->logCall($method, $path, $body, [
                'http_code' => $httpCode,
                'error'     => $this->lastError,
                'response'  => $decoded,
            ]);
            return null;
        }

        // Esgotou retries
        $this->logCall($method, $path, $body, [
            'http_code' => $this->lastHttpCode,
            'error'     => $this->lastError ?? 'Esgotou tentativas de retry',
            'raw'       => $lastResponseRaw,
        ]);
        return null;
    }

    /**
     * Gera Idempotency-Key determinístico para a operação.
     *
     * Combina método + path + hash do body + timestamp arredondado (janela 1min)
     * para garantir que retries automáticos usem a mesma chave, mas operações
     * diferentes gerem chaves distintas.
     */
    private function generateIdempotencyKey(string $method, string $path, ?string $body): string
    {
        $window = (int) floor(time() / 60); // janela de 1 minuto
        $seed   = $method . '|' . $path . '|' . ($body ?? '') . '|' . $window;
        return hash('sha256', $seed);
    }

    /**
     * Backoff exponencial com jitter.
     */
    private function sleepBackoff(int $attempt): void
    {
        $base   = self::RETRY_BACKOFF_MS * (2 ** ($attempt - 1));
        $jitter = random_int(0, (int) ($base * 0.3));
        usleep(($base + $jitter) * 1000);
    }

    /**
     * Extrai mensagem de erro estruturada da resposta do MP.
     */
    private function extractErrorMessage(array $response, int $httpCode): string
    {
        // Formato padrão do MP
        if (!empty($response['message'])) {
            $msg = (string) $response['message'];

            // Adiciona causa(s) detalhada(s) se houver
            if (!empty($response['cause']) && is_array($response['cause'])) {
                $causes = [];
                foreach ($response['cause'] as $cause) {
                    if (isset($cause['description'])) {
                        $causes[] = $cause['description'];
                    } elseif (isset($cause['code'])) {
                        $causes[] = 'code=' . $cause['code'];
                    }
                }
                if (!empty($causes)) {
                    $msg .= ' (' . implode('; ', $causes) . ')';
                }
            }

            return "[HTTP {$httpCode}] {$msg}";
        }

        if (!empty($response['error'])) {
            return "[HTTP {$httpCode}] " . (string) $response['error'];
        }

        return "[HTTP {$httpCode}] Erro desconhecido na API do Mercado Pago.";
    }

    /**
     * Registra chamada no Module Log do WHMCS (com mascaramento).
     */
    private function logCall(string $method, string $path, mixed $request, mixed $response): void
    {
        if (!function_exists('logModuleCall')) {
            return;
        }

        try {
            logModuleCall(
                'seixastec_mercadopago',
                "API {$method} {$path}",
                is_string($request)  ? $request  : json_encode($request,  JSON_UNESCAPED_UNICODE),
                is_string($response) ? $response : json_encode($response, JSON_UNESCAPED_UNICODE),
                '',
                [
                    $this->accessToken,
                    'access_token',
                    'accessToken',
                    'Authorization',
                    'Bearer',
                ]
            );
        } catch (\Throwable $e) {
            // Falha silenciosa - não impede operação principal
        }
    }
}
