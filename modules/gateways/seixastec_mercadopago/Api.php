<?php
/**
 * Mercado Pago - Cliente HTTP da API v1
 *
 * Wrapper completo para a API REST do Mercado Pago.
 *
 * Endpoints suportados:
 *   - GET    /users/me                       Valida credenciais / dados da conta
 *   - POST   /checkout/preferences           Cria preferência (Checkout Pro)
 *   - GET    /checkout/preferences/{id}      Consulta preferência
 *   - PUT    /checkout/preferences/{id}      Atualiza preferência
 *   - POST   /v1/payments                    Cria pagamento direto (PIX/Boleto/Cartão)
 *   - GET    /v1/payments/{id}               Consulta pagamento
 *   - GET    /v1/payments/search             Busca pagamentos
 *   - POST   /v1/payments/{id}/refunds       Reembolso total/parcial
 *   - GET    /v1/payments/{id}/refunds       Lista reembolsos
 *   - GET    /v1/payments/{id}/refunds/{rid} Consulta reembolso
 *   - GET    /merchant_orders/{id}           Consulta merchant order
 *   - GET    /v1/payment_methods             Lista métodos de pagamento
 *
 * Recursos:
 *   - Retry automático com backoff exponencial + jitter (3 tentativas)
 *   - Idempotency-Key gerado UMA vez por operação lógica
 *   - Timeout configurável (padrão: 30s)
 *   - SSL verification estrita
 *   - Mascaramento de credenciais em logs
 *   - Tratamento estruturado de erros (PolicyAgent, cause detalhado)
 *   - X-Product-Id configurável (não hardcoded)
 *   - Detecção automática de ambiente (sandbox vs produção pelo token)
 *
 * Compatível com: WHMCS 8.x / 9.x | PHP 8.1+ | Mercado Pago API v1
 *
 * @package   SeixasTec\MercadoPago
 * @author    Eduardo Seixas <https://github.com/eseixas>
 * @version   1.7.0
 * @license   GPL-3.0
 */

declare(strict_types=1);

namespace WHMCS\Module\Gateway\SeixastecMercadoPago;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class Api
{
    /** Versão da classe (usada no User-Agent e logs). */
    public const VERSION = '1.7.0';

    /** Endpoint base da API do Mercado Pago. */
    private const BASE_URL = 'https://api.mercadopago.com';

    /** User-Agent identificando o integrador. */
    private const USER_AGENT_PREFIX = 'WHMCS-MercadoPago-Seixastec';

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

    /** Prefixos de tokens de teste do Mercado Pago. */
    private const TEST_TOKEN_PREFIXES = ['TEST-', 'APP_USR-TEST-'];

    private string $accessToken;
    private bool $debugMode;
    private ?string $productId;
    private string $moduleName;

    private ?string $lastError = null;
    private ?int $lastHttpCode = null;
    private ?array $lastResponse = null;
    private ?string $lastRequestId = null;

    /**
     * @param string  $accessToken Access Token do Mercado Pago (APP_USR-... ou TEST-...)
     * @param bool    $debugMode   Habilita logs detalhados
     * @param ?string $productId   X-Product-Id (opcional; identificador da aplicação no MP)
     * @param string  $moduleName  Nome do módulo (usado no logModuleCall)
     *
     * @throws \InvalidArgumentException Se o token estiver vazio.
     */
    public function __construct(
        string $accessToken,
        bool $debugMode = false,
        ?string $productId = null,
        string $moduleName = 'seixastec_mercadopago'
    ) {
        $accessToken = trim($accessToken);
        if ($accessToken === '') {
            throw new \InvalidArgumentException('Access Token é obrigatório.');
        }

        $this->accessToken = $accessToken;
        $this->debugMode   = $debugMode;
        $this->productId   = ($productId !== null && trim($productId) !== '') ? trim($productId) : null;
        $this->moduleName  = $moduleName;
    }

    // =======================================================================
    // CONTA / CREDENCIAIS
    // =======================================================================

    /**
     * Retorna dados da conta dona do Access Token.
     * Útil para validação e auditoria.
     */
    public function getAccount(): ?array
    {
        return $this->request('GET', '/users/me');
    }

    /**
     * Valida se o Access Token é válido e ativo.
     */
    public function testCredentials(): bool
    {
        $result = $this->getAccount();
        return $result !== null
            && isset($result['id'])
            && !empty($result['site_id']);
    }

    /**
     * Detecta se o token configurado é de ambiente de testes.
     */
    public function isSandbox(): bool
    {
        foreach (self::TEST_TOKEN_PREFIXES as $prefix) {
            if (str_starts_with($this->accessToken, $prefix)) {
                return true;
            }
        }
        return false;
    }

    // =======================================================================
    // PREFERÊNCIAS (Checkout Pro)
    // =======================================================================

    /**
     * Cria uma preferência de pagamento (Checkout Pro).
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

    /**
     * Atualiza uma preferência existente.
     */
    public function updatePreference(string $preferenceId, array $changes): ?array
    {
        return $this->request(
            'PUT',
            '/checkout/preferences/' . urlencode($preferenceId),
            $changes
        );
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
     * Cancela um pagamento pendente (status -> cancelled).
     */
    public function cancelPayment(string $paymentId): ?array
    {
        return $this->request(
            'PUT',
            '/v1/payments/' . urlencode($paymentId),
            ['status' => 'cancelled']
        );
    }

    /**
     * Busca pagamentos por external_reference (= ID da fatura WHMCS).
     *
     * @return array|null Estrutura `{ paging: {...}, results: [...] }`
     */
    public function searchPaymentsByExternalReference(string $externalReference, int $limit = 20): ?array
    {
        return $this->searchPayments([
            'external_reference' => $externalReference,
            'sort'               => 'date_created',
            'criteria'           => 'desc',
        ], $limit);
    }

    /**
     * Busca pagamentos com filtros customizados.
     */
    public function searchPayments(array $filters, int $limit = 20): ?array
    {
        $filters['limit'] = max(1, min($limit, 50));

        // http_build_query com PHP_QUERY_RFC3986 garante encoding correto para o MP
        $query = http_build_query($filters, '', '&', PHP_QUERY_RFC3986);
        return $this->request('GET', '/v1/payments/search?' . $query);
    }

    // =======================================================================
    // REEMBOLSOS
    // =======================================================================

    /**
     * Reembolsa um pagamento (total ou parcial).
     *
     * @param string     $paymentId ID do pagamento
     * @param float|null $amount    Valor a reembolsar; null = reembolso total
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
    // MÉTODOS DE PAGAMENTO
    // =======================================================================

    /**
     * Lista todos os métodos de pagamento disponíveis para a conta.
     */
    public function getPaymentMethods(): ?array
    {
        return $this->request('GET', '/v1/payment_methods');
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

    /**
     * Retorna o x-request-id da última resposta do MP (útil para abrir suporte).
     */
    public function getLastRequestId(): ?string
    {
        return $this->lastRequestId;
    }

    /**
     * Indica se a última chamada foi bem sucedida (HTTP 2xx).
     */
    public function wasSuccessful(): bool
    {
        return $this->lastHttpCode !== null
            && $this->lastHttpCode >= 200
            && $this->lastHttpCode < 300;
    }

    // =======================================================================
    // CORE HTTP
    // =======================================================================

    /**
     * Executa requisição HTTP com retry e backoff exponencial.
     *
     * @param string     $method     POST, GET, PUT, DELETE
     * @param string     $path       Caminho relativo (começa com `/`)
     * @param array|null $body       Corpo da requisição (apenas POST/PUT/PATCH)
     * @param bool       $idempotent Adiciona X-Idempotency-Key
     */
    private function request(
        string $method,
        string $path,
        ?array $body = null,
        bool $idempotent = false
    ): ?array {
        $this->lastError     = null;
        $this->lastHttpCode  = null;
        $this->lastResponse  = null;
        $this->lastRequestId = null;

        $url      = self::BASE_URL . $path;
        $jsonBody = null;

        if ($body !== null && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $jsonBody = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($jsonBody === false) {
                $this->lastError = 'Falha ao serializar body: ' . json_last_error_msg();
                $this->logCall($method, $path, $body, $this->lastError);
                return null;
            }
        }

        $headers = $this->buildHeaders();

        // Idempotency-Key gerada UMA VEZ (fora do loop de retry) para garantir
        // que o MP reconheça retries automáticos como a mesma operação lógica.
        if ($idempotent) {
            $headers[] = 'X-Idempotency-Key: ' . $this->generateIdempotencyKey($method, $path, $jsonBody);
        }

        $attempt         = 0;
        $lastResponseRaw = '';

        while ($attempt < self::MAX_RETRIES) {
            $attempt++;

            [$rawResponse, $httpCode, $curlErrno, $curlError, $responseHeaders] =
                $this->executeCurl($url, $method, $headers, $jsonBody);

            $this->lastHttpCode  = $httpCode;
            $this->lastRequestId = $responseHeaders['x-request-id'] ?? null;
            $lastResponseRaw     = is_string($rawResponse) ? $rawResponse : '';

            // ---- Erro de rede / cURL ----
            if ($rawResponse === false || $curlErrno !== 0) {
                $this->lastError = "cURL #{$curlErrno}: {$curlError}";

                if ($attempt < self::MAX_RETRIES && $this->isRetryableCurlError($curlErrno)) {
                    $this->sleepBackoff($attempt);
                    continue;
                }

                $this->logCall($method, $path, $body, $this->lastError);
                return null;
            }

            // ---- HTTP retryable (5xx, 429, 408) ----
            if (in_array($httpCode, self::RETRYABLE_STATUS, true) && $attempt < self::MAX_RETRIES) {
                $this->sleepBackoff($attempt, $responseHeaders['retry-after'] ?? null);
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
                    'http_code'  => $httpCode,
                    'request_id' => $this->lastRequestId,
                    'response'   => $decoded,
                ]);
                return $decoded;
            }

            // ---- Erro HTTP definitivo (4xx não-retryable) ----
            $this->lastError = $this->extractErrorMessage($decoded, $httpCode);
            $this->logCall($method, $path, $body, [
                'http_code'  => $httpCode,
                'request_id' => $this->lastRequestId,
                'error'      => $this->lastError,
                'response'   => $decoded,
            ]);
            return null;
        }

        // Esgotou retries
        $this->lastError = $this->lastError ?? 'Esgotou tentativas de retry sem sucesso.';
        $this->logCall($method, $path, $body, [
            'http_code'  => $this->lastHttpCode,
            'request_id' => $this->lastRequestId,
            'error'      => $this->lastError,
            'raw'        => $lastResponseRaw,
        ]);
        return null;
    }

    /**
     * Monta o array de headers padrão.
     */
    private function buildHeaders(): array
    {
        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: ' . self::USER_AGENT_PREFIX . '/' . self::VERSION
                . ' (PHP/' . PHP_VERSION . '; +https://github.com/eseixas/mercadopago-whmcs)',
        ];

        if ($this->productId !== null) {
            $headers[] = 'X-Product-Id: ' . $this->productId;
        }

        return $headers;
    }

    /**
     * Executa cURL e retorna tudo necessário para o caller decidir.
     *
     * @return array{0:string|false,1:int,2:int,3:string,4:array<string,string>}
     */
    private function executeCurl(
        string $url,
        string $method,
        array $headers,
        ?string $jsonBody
    ): array {
        $responseHeaders = [];

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
            CURLOPT_HEADERFUNCTION => function ($curl, $header) use (&$responseHeaders) {
                $len   = strlen($header);
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $len;
            },
        ]);

        if ($jsonBody !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        }

        $rawResponse = curl_exec($ch);
        $httpCode    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno   = curl_errno($ch);
        $curlError   = curl_error($ch);
        curl_close($ch);

        return [$rawResponse, $httpCode, $curlErrno, $curlError, $responseHeaders];
    }

    /**
     * Indica se um erro de cURL merece retry.
     */
    private function isRetryableCurlError(int $errno): bool
    {
        // 6=DNS, 7=connect, 28=timeout, 35=SSL connect, 52=empty reply, 56=recv failure
        return in_array($errno, [6, 7, 28, 35, 52, 56], true);
    }

    /**
     * Gera Idempotency-Key único por operação lógica (não muda em retry).
     */
    private function generateIdempotencyKey(string $method, string $path, ?string $body): string
    {
        $seed = $method . '|' . $path . '|' . ($body ?? '') . '|' . bin2hex(random_bytes(8));
        return hash('sha256', $seed);
    }

    /**
     * Backoff exponencial com jitter, respeitando Retry-After do servidor.
     */
    private function sleepBackoff(int $attempt, ?string $retryAfter = null): void
    {
        // Se o servidor mandou Retry-After (em segundos), prioriza isso
        if ($retryAfter !== null && is_numeric($retryAfter)) {
            $seconds = max(1, min((int) $retryAfter, 10)); // cap em 10s
            sleep($seconds);
            return;
        }

        $base   = self::RETRY_BACKOFF_MS * (2 ** ($attempt - 1));
        $jitter = random_int(0, (int) ($base * 0.3));
        usleep(((int) $base + $jitter) * 1000);
    }

    /**
     * Extrai mensagem de erro estruturada da resposta do MP.
     */
    private function extractErrorMessage(array $response, int $httpCode): string
    {
        $diagnosticText = strtolower(
            json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''
        );

        // Detecção de PolicyAgent / problemas de credenciais
        if (
            in_array($httpCode, [401, 403], true)
            && (
                str_contains($diagnosticText, 'pa_unauthorized_result_from_policies')
                || str_contains($diagnosticText, 'policyagent')
                || str_contains($diagnosticText, 'at least one policy returned unauthorized')
                || str_contains($diagnosticText, 'unauthorized use of live credentials')
            )
        ) {
            return "[HTTP {$httpCode}] Mercado Pago não autorizou a requisição. "
                . 'Verifique se o Access Token está correto, se o ambiente '
                . '(Sandbox/Produção) corresponde ao tipo do token, e se a aplicação '
                . 'possui as permissões necessárias no painel do MP.';
        }

        // Formato padrão de erro do MP
        if (!empty($response['message'])) {
            $msg = (string) $response['message'];

            if (!empty($response['cause']) && is_array($response['cause'])) {
                $causes = [];
                foreach ($response['cause'] as $cause) {
                    if (isset($cause['description'])) {
                        $causes[] = (string) $cause['description'];
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
            $err = (string) $response['error'];
            if (!empty($response['error_description'])) {
                $err .= ' - ' . (string) $response['error_description'];
            }
            return "[HTTP {$httpCode}] {$err}";
        }

        return "[HTTP {$httpCode}] Erro desconhecido na API do Mercado Pago.";
    }

    /**
     * Registra chamada no Module Log do WHMCS (com mascaramento de credenciais).
     */
    private function logCall(string $method, string $path, mixed $request, mixed $response): void
    {
        if (!$this->debugMode || !function_exists('logModuleCall')) {
            return;
        }

        try {
            logModuleCall(
                $this->moduleName,
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
                    'X-Idempotency-Key',
                ]
            );
        } catch (\Throwable $e) {
            // Falha silenciosa - log não pode quebrar operação principal
        }
    }
}
