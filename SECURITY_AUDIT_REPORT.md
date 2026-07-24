# Relatório de Auditoria de Segurança — Módulo MercadoPago WHMCS

**Módulo:** `seixastec_mercadopago` v2.3.0  
**Data da auditoria:** 2026-07-24  
**Escopo:** Todos os 16 arquivos PHP + 7 templates Smarty + composer.json  
**Classificação:** Critical / High / Medium / Low / Info

---

## Resumo Executivo

| Severidade | Quantidade |
|------------|-----------|
| **Critical** | 2 |
| **High** | 4 |
| **Medium** | 7 |
| **Low** | 4 |
| **Info** | 2 |
| **Total** | **19** |

O módulo apresenta boa arquitetura geral (strict_types, WHMCS guard, SSL verification, HMAC-SHA256, idempotency keys), mas possui **falhas críticas** no fluxo de callback/webhook e **vetores de XSS** nos templates que exigem correção imediata antes de uso em produção.

---

## VULNERABILIDADES CRÍTICAS

---

### VULN-01: Webhook sem Validação HMAC quando `webhookSecret` não configurado (CRITICAL)

**Arquivo:** `modules/gateways/callback/seixastec_mercadopago.php`, linhas 117–134  
**Categoria:** Autenticação / Webhook Spoofing

**Descrição:**  
Quando o campo `webhookSecret` está vazio (configuração padrão — o campo não é obrigatório na instalação), a validação de assinatura HMAC é **completamente ignorada**. O callback processa qualquer requisição recebida como se fosse legítima do MercadoPago.

**Código vulnerável:**
```php
// Linha 117-134
if ($webhookSecret !== '') {
    // ... validação HMAC ...
} else {
    // Em produção sem secret é arriscado; loga aviso mas processa
    $log('Webhook WARNING', 'Webhook Secret não configurado - validação HMAC desabilitada.');
}
// ← PROCESSA NORMALMENTE SEM VALIDAÇÃO
```

**Prova de conceito / Cenário de ataque:**
```bash
# Atacante marca qualquer fatura como paga com valor arbitrário:
curl -X POST https://loja.com/modules/gateways/callback/seixastec_mercadopago.php \
  -H "Content-Type: application/json" \
  -d '{"type":"payment","data":{"id":"12345"}}'

# O callback buscará o payment_id 12345 na API do MP.
# Se o atacante criar um pagamento de R$0,01 com external_reference
# apontando para uma fatura de R$5.000, a fatura será marcada como paga.
```

**Correção recomendada:**
```php
// TORNA webhookSecret OBRIGATÓRIO — recusa processar sem validação
if ($webhookSecret === '') {
    $log('Webhook BLOCKED', 'Webhook Secret não configurado. Recusando requisição.');
    http_response_code(503);
    exit('Webhook secret not configured');
}

// Validação HMAC sempre executada
$signatureHeader = $headers['x-signature']  ?? '';
$requestIdHeader = $headers['x-request-id'] ?? '';
$dataId = $_GET['data.id'] ?? $_GET['id'] ?? ($payload['data']['id'] ?? '');

if (!_seixastec_mp_validate_signature($signatureHeader, $requestIdHeader, (string) $dataId, $webhookSecret)) {
    http_response_code(401);
    exit('Invalid signature');
}
```

---

### VULN-02: Callback não verifica valor do pagamento contra a fatura (CRITICAL)

**Arquivo:** `modules/gateways/callback/seixastec_mercadopago.php`, linhas 225–268  
**Categoria:** Pagamento — Amount Tampering

**Descrição:**  
O callback aceita `transaction_amount` diretamente da resposta da API do MP e o registra via `addInvoicePayment()` **sem comparar com o total da fatura WHMCS**. Um atacante que consiga forjar ou manipular um webhook (ver VULN-01) pode criar um pagamento de R$0,01 e marcar uma fatura de R$10.000 como paga.

Mesmo com HMAC ativo, se o atacante tiver acesso a uma conta MP e criar um pagamento com `external_reference` de uma fatura que não é sua, o valor não será validado.

**Código vulnerável:**
```php
// Linha 227
$amount = (float) ($payment['transaction_amount'] ?? 0);
// ...
// Linha 262-268 — registra SEM verificar contra total da fatura
addInvoicePayment(
    $invoiceId,
    $paymentId,
    $amount,       // ← valor do MP, não da fatura
    $fee,
    $gateway['name']
);
```

**Cenário de ataque:**
1. Atacante identifica fatura #1234 de R$5.000 (via enumeração de `external_reference`)
2. Cria pagamento PIX de R$0,01 na própria conta MP com `external_reference = "1234"`
3. Dispara webhook (ou espera notificação automática)
4. Callback registra pagamento de R$0,01 na fatura de R$5.000

**Correção recomendada:**
```php
// Após localizar a fatura, validar o valor
$invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
if (!$invoice) {
    $log('Webhook WARN', "Fatura {$invoiceId} não encontrada.");
    return;
}

$invoiceTotal = (float) $invoice->total;
$tolerance = 0.05; // tolerância de R$0,05 para arredondamentos

if (abs($amount - $invoiceTotal) > $tolerance) {
    $log('Webhook AMOUNT MISMATCH', [
        'payment_id'     => $paymentId,
        'invoice_id'     => $invoiceId,
        'expected'       => $invoiceTotal,
        'received'       => $amount,
    ]);
    // Registra como pagamento parcial ou rejeita
    _seixastec_mp_add_invoice_note(
        $invoiceId,
        "⚠️ ALERTA: Pagamento {$paymentId} com valor R$ {$amount} divergente "
        . "do total da fatura R$ {$invoiceTotal}. Verificar manualmente."
    );
    return; // NÃO registra automaticamente
}
```

---

## VULNERABILIDADES ALTAS

---

### VULN-03: XSS Armazenado via template `alert.tpl` — `$message` sem escape (HIGH)

**Arquivo:** `modules/gateways/seixastec_mercadopago/templates/alert.tpl`, linha 12  
**Arquivo relacionado:** `TemplateRenderer.php`, linha 102 (`escape_html = false`)  
**Categoria:** XSS

**Descrição:**  
O template `alert.tpl` renderiza `{$message}` **sem escape HTML**. Combinado com `escape_html = false` no Smarty, qualquer dado não sanitizado que chegue a `$message` será interpretado como HTML/JavaScript.

**Código vulnerável:**
```smarty
{* alert.tpl, linha 12 *}
{if $icon}<strong>{$icon}</strong> {/if}{$message}
{* ← $message renderizado como HTML bruto *}
```

Embora a maioria dos callers use `htmlspecialchars()`, o padrão é inseguro — um único caller que esqueça o escape cria XSS.

**Vetores atuais:**
- `_seixastec_mp_alert('danger', 'Erro: ' . htmlspecialchars($e->getMessage()))` — OK
- Mas `htmlspecialchars()` sem `ENT_QUOTES` em alguns callers permite escape via aspas simples

**Correção recomendada:**
```smarty
{* alert.tpl — escapar SEMPRE no template *}
{if $icon}<strong>{$icon|escape:'html'}</strong> {/if}{$message|escape:'html'}
```

Ou ativar auto-escape global:
```php
// TemplateRenderer.php, linha 102
$smarty->escape_html = true; // escape automático de TODAS as variáveis
```

---

### VULN-04: CSRF em Ação Administrativa via GET — Botão "Sincronizar" (HIGH)

**Arquivo:** `includes/hooks/seixastec_mercadopago.php`, linhas 246–265  
**Categoria:** CSRF / Authorization

**Descrição:**  
A ação de sincronização de pagamentos com o MP é disparada via parâmetro GET (`mp_sync=1`) sem qualquer token CSRF. Um atacante pode forçar um admin a sincronizar pagamentos (potencialmente aplicando pagamentos fraudulentos) através de um link malicioso.

**Código vulnerável:**
```php
// Linha 246
if (($_GET['mp_sync'] ?? '') === '1' && (int) ($_GET['id'] ?? 0) === $invoiceId) {
    $result = _seixastec_mp_sync_invoice($invoiceId);
    // ← Executa sincronização sem token CSRF
}

// Linha 261-264 — Link gerado sem token
return <<<HTML
<a href="invoices.php?action=edit&id={$invoiceId}&mp_sync=1" ...>
HTML;
```

**Cenário de ataque:**
```html
<!-- E-mail ou fórum malicioso -->
<img src="https://loja.com/admin/invoices.php?action=edit&id=1234&mp_sync=1" width="0" height="0">
<!-- Admin logado acessa a página → sincronização executada automaticamente -->
```

**Correção recomendada:**
```php
// Usar POST + token CSRF do WHMCS
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['mp_sync'] ?? '') === '1'
    && check_token() // WHMCS CSRF token
) {
    $result = _seixastec_mp_sync_invoice($invoiceId);
}

// Formulário ao invés de link
return <<<HTML
<form method="post" action="invoices.php?action=edit&id={$invoiceId}">
    <input type="hidden" name="token" value="{$_SESSION['token']}">
    <input type="hidden" name="mp_sync" value="1">
    <button type="submit" class="btn btn-info btn-sm">
        <i class="fa fa-refresh"></i> Sincronizar com Mercado Pago
    </button>
</form>
HTML;
```

---

### VULN-05: `payment_method_id` Controlado pelo Cliente em `process.php` (HIGH)

**Arquivo:** `modules/gateways/seixastec_mercadopago/process.php`, linha 275  
**Categoria:** Input Validation / Payment Manipulation

**Descrição:**  
No fluxo de boleto, o `payment_method_id` é aceito diretamente do `formData` enviado pelo cliente sem validação contra uma lista de métodos permitidos. Um atacante pode especificar um método de pagamento não configurado ou inexistente.

**Código vulnerável:**
```php
// Linha 275
$payload = array_merge($basePayload, [
    'payment_method_id' => $formData['payment_method_id'] ?? 'bolbradesco',
    // ← $formData vem do cliente, sem validação
]);
```

**Cenário de ataque:**
```json
{
    "invoice_id": 1234,
    "payment_method": "ticket",
    "form_data": {
        "payment_method_id": "account_money"
    }
}
// Atacante força uso de account_money (saldo MP) ao invés de boleto
```

**Correção recomendada:**
```php
// Whitelist de métodos de boleto permitidos
$allowedTicketMethods = ['bolbradesco', 'pec'];
$requestedMethod = (string) ($formData['payment_method_id'] ?? 'bolbradesco');

if (!in_array($requestedMethod, $allowedTicketMethods, true)) {
    respond(false, 'Método de boleto não suportado.', [], 400);
}

$payload = array_merge($basePayload, [
    'payment_method_id' => $requestedMethod,
]);
```

---

### VULN-06: Race Condition no Processamento de Pagamentos do Callback (HIGH)

**Arquivo:** `modules/gateways/callback/seixastec_mercadopago.php`, linhas 244–268  
**Categoria:** Condição de Corrida / Pagamento Duplicado

**Descrição:**  
A verificação de duplicidade (`checkCbTransID`) e o registro do pagamento (`addInvoicePayment`) **não são atômicos**. Dois webhooks simultâneos com o mesmo `payment_id` podem passar na verificação antes que qualquer um registre o pagamento, resultando em pagamento duplicado.

**Código vulnerável:**
```php
// Linha 245 — verificação NÃO atômica
checkCbTransID($paymentId);
// ← janela de corrida entre verificação e registro
// Linha 262-268
addInvoicePayment($invoiceId, $paymentId, $amount, $fee, $gateway['name']);
```

**Correção recomendada:**
```php
// Usar lock de banco de dados ou lock file
$lockFile = sys_get_temp_dir() . '/mp_payment_' . md5($paymentId) . '.lock';
$lockHandle = fopen($lockFile, 'c');

if ($lockHandle && flock($lockHandle, LOCK_EX | LOCK_NB)) {
    try {
        checkCbTransID($paymentId);
        addInvoicePayment($invoiceId, $paymentId, $amount, $fee, $gateway['name']);
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        @unlink($lockFile);
    }
} else {
    $log('Webhook SKIP', "Pagamento {$paymentId} já em processamento (lock ativo).");
}
```

---

## VULNERABILIDADES MÉDIAS

---

### VULN-07: XSS via `$qrCodeBase64` sem Escape em `pix.tpl` (MEDIUM)

**Arquivo:** `modules/gateways/seixastec_mercadopago/templates/pix.tpl`, linha 19  
**Categoria:** XSS

**Descrição:**  
A variável `$qrCodeBase64` é inserida diretamente no atributo `src` de uma tag `<img>` sem escape. Se o dado for comprometido (ex: resposta MP manipulada via MITM, ou dado armazenado corrompido), é possível injetar HTML/JavaScript.

**Código vulnerável:**
```smarty
{* pix.tpl, linha 19 *}
<img src="data:image/png;base64,{$qrCodeBase64}" alt="QR Code Pix" ...>
{* ← sem |escape:'html' *}
```

**Correção:**
```smarty
<img src="data:image/png;base64,{$qrCodeBase64|escape:'html'}" alt="QR Code Pix" ...>
```

---

### VULN-08: CDN sem Subresource Integrity (SRI) — Supply Chain Attack (MEDIUM)

**Arquivo:** `modules/gateways/seixastec_mercadopago/pay.php`, linhas 181–182, 318  
**Categoria:** Supply Chain / Integrity

**Descrição:**  
Três recursos críticos são carregados de CDNs externas sem hashes SRI. Se o CDN for comprometido, o atacante pode injetar JavaScript malicioso na página de pagamento.

**Código vulnerável:**
```html
<!-- Linha 181-182 -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
<!-- Linha 318 -->
<script src="https://sdk.mercadopago.com/js/v2"></script>
```

**Correção recomendada:**
```html
<link rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
      integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN"
      crossorigin="anonymous">

<!-- Para o SDK do MP, usar self-host ou SRI quando disponível -->
```

---

### VULN-09: Sem Rate Limiting nos Endpoints de Pagamento (MEDIUM)

**Arquivos:** `process.php`, `callback/seixastec_mercadopago.php`  
**Categoria:** Availability / Abuse

**Descrição:**  
Nenhum dos endpoints possui rate limiting. Um atacante pode:
- Disparar milhares de requisições ao `process.php` criando pagamentos em massa na API do MP
- Enviar webhooks falsos em volume para sobrecarregar o servidor
- Enumerar IDs de fatura via callback

**Correção recomendada:**
```php
// process.php — rate limit por sessão
$rateLimitKey = 'mp_rate_' . $clientId;
$attempts = $_SESSION[$rateLimitKey] ?? ['count' => 0, 'reset' => time() + 60];

if (time() > $attempts['reset']) {
    $attempts = ['count' => 0, 'reset' => time() + 60];
}

if ($attempts['count'] >= 5) { // máx 5 tentativas/min
    respond(false, 'Muitas tentativas. Aguarde 1 minuto.', [], 429);
}

$attempts['count']++;
$_SESSION[$rateLimitKey] = $attempts;
```

---

### VULN-10: Dados Sensíveis em Logs de Debug (MEDIUM)

**Arquivo:** `modules/gateways/callback/seixastec_mercadopago.php`, linhas 104–111  
**Categoria:** Sensitive Data Exposure

**Descrição:**  
Quando `debugLog` está ativo, o callback loga **todos os headers HTTP** (que podem conter tokens), query params e o body completo da requisição. Estes logs são armazenados no banco de dados WHMCS (`tblmodulelog`) acessível a admins.

**Código vulnerável:**
```php
if ($debugLog) {
    $log('Webhook RECEIVED', [
        'headers' => $headers,    // ← pode conter authorization, cookies
        'query'   => $_GET,       // ← pode conter tokens na URL
        'body'    => $payload,
        'raw'     => $rawBody,
    ]);
}
```

**Correção recomendada:**
```php
if ($debugLog) {
    $safeHeaders = array_diff_key($headers, array_flip([
        'authorization', 'cookie', 'x-signature', 'set-cookie'
    ]));
    $log('Webhook RECEIVED', [
        'headers' => $safeHeaders,
        'query'   => array_diff_key($_GET, array_flip(['token', 'access_token'])),
        'body'    => $payload,
        // 'raw' removido — redundante com body
    ]);
}
```

---

### VULN-11: CSRF Insuficiente em `process.php` — Apenas Header XHR (MEDIUM)

**Arquivo:** `modules/gateways/seixastec_mercadopago/process.php`, linhas 77–81  
**Categoria:** CSRF

**Descrição:**  
A proteção CSRF baseia-se apenas no header `X-Requested-With: XMLHttpRequest`. Embora este header não possa ser enviado via `<form>` cross-origin, ele **pode ser contornado** em cenários de:
- Misconfiguração CORS no servidor (ex: `Access-Control-Allow-Origin: *`)
- Plugins de browser maliciosos
- Subdomain takeover

Não há token CSRF real vinculado à sessão.

**Correção recomendada:**
```php
// Adicionar token CSRF real
session_start(); // WHMCS já inicia
$csrfToken = $_SESSION['mp_csrf_token'] ?? bin2hex(random_bytes(32));
$_SESSION['mp_csrf_token'] = $csrfToken;

// Em pay.php, incluir no JS:
// body: JSON.stringify({ ..., csrf_token: '<?= $csrfToken ?>' })

// Em process.php, validar:
$receivedToken = (string) ($input['csrf_token'] ?? '');
if (!hash_equals($_SESSION['mp_csrf_token'] ?? '', $receivedToken)) {
    respond(false, 'Token CSRF inválido.', [], 403);
}
```

---

### VULN-12: `_mp_diag.php` — Arquivo de Diagnóstico com Acesso a Credenciais (MEDIUM)

**Arquivo:** `_mp_diag.php`, linhas 1–133  
**Categoria:** Information Disclosure

**Descrição:**  
Embora gitignored e protegido por sessão admin, este arquivo:
- Faz uma chamada real à API do MP com o Access Token completo
- Expõe o tipo de credencial e token mascarado
- Se acidentalmente deployado (ex: via FTP com `deploy_ftp.ps1`), fica acessível em `https://loja.com/_mp_diag.php`
- A verificação `$_SESSION['adminid']` pode ser bypassed se WHMCS não estiver bootstrapped corretamente

**Correção recomendada:**
- Adicionar ao `.htaccess` / nginx: `deny from all` para `_mp_diag.php`
- Ou renomear para path não-guessable e adicionar token de acesso

---

### VULN-13: `escape_html = false` Global no Smarty (MEDIUM)

**Arquivo:** `modules/gateways/seixastec_mercadopago/TemplateRenderer.php`, linha 102  
**Categoria:** XSS (configuração insegura)

**Descrição:**  
O Smarty está configurado com `escape_html = false`, desativando o auto-escape de TODAS as variáveis em TODOS os templates. Isso transfere a responsabilidade de escape para cada template individual, criando risco de XSS por omissão.

**Templates com variáveis sem escape:**
- `alert.tpl`: `{$message}` — sem escape
- `pix.tpl`: `{$qrCodeBase64}` — sem escape no atributo `src`
- `pix.tpl`: `{$invoiceId}` — sem escape no atributo `id` e em contexto JavaScript

**Correção:**
```php
$smarty->escape_html = true; // Auto-escape global
// Nos templates que precisam de HTML intencional, usar {$var nofilter}
```

---

## VULNERABILIDADES BAIXAS

---

### VULN-14: MD5 na Chave de Idempotência (LOW)

**Arquivo:** `modules/gateways/seixastec_mercadopago/process.php`, linha 179  
**Categoria:** Cryptographic Issues

**Descrição:**  
`md5()` é usado para compor a chave de idempotência. Embora não seja um contexto de segurança criptográfica (é apenas uma chave de dedup), MD5 é considerado obsoleto e pode gerar colisões.

```php
substr(md5((string) $amount), 0, 10)
```

**Correção:**
```php
substr(hash('sha256', (string) $amount), 0, 10)
```

---

### VULN-15: Stack Trace Completo em Logs (LOW)

**Arquivo:** `modules/gateways/seixastec_mercadopago/process.php`, linhas 373–376  
**Categoria:** Information Disclosure

**Descrição:**  
Exceções não tratadas logam o stack trace completo (`$e->getTraceAsString()`), que pode revelar caminhos internos do servidor, versões de bibliotecas e estrutura do código.

```php
mpLog('process_exception', $input, [
    'message' => $e->getMessage(),
    'trace'   => $e->getTraceAsString(), // ← caminhos internos
]);
```

**Correção:** Logar apenas mensagem e arquivo/linha, não o trace completo.

---

### VULN-16: Sem Header `Content-Security-Policy` em `pay.php` (LOW)

**Arquivo:** `modules/gateways/seixastec_mercadopago/pay.php`  
**Categoria:** Defense in Depth

**Descrição:**  
A página de checkout não envia headers de segurança:
- `Content-Security-Policy`
- `X-Frame-Options`
- `Strict-Transport-Security`

Isso permite clickjacking e dificulta mitigação de XSS.

**Correção:**
```php
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://sdk.mercadopago.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; img-src 'self' data:;");
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
```

---

### VULN-17: Interpolação de String em Query SQL (LOW)

**Arquivo:** `includes/hooks/seixastec_mp_install.php`, linha 306  
**Categoria:** SQL Injection (risco mínimo)

**Descrição:**
```php
$rows = Capsule::select("SHOW INDEX FROM `{$tableName}`");
```
`$tableName` é uma constante (`SEIXASTEC_MP_TABLE`), então o risco é mínimo. Porém, o padrão de interpolação em SQL é desencorajado.

**Correção:**
```php
$rows = Capsule::select("SHOW INDEX FROM `" . SEIXASTEC_MP_TABLE . "`");
// Ou usar Schema Builder: Capsule::schema()->getIndexes(SEIXASTEC_MP_TABLE)
```

---

## INFORMAÇÕES

---

### INFO-01: Dependências Declaradas mas Não Utilizadas no Runtime

**Arquivo:** `composer.json`, linhas 40–43

O módulo declara dependências que **não são usadas** no código-fonte principal:
- `mercadopago/dx-php` — a API é implementada manualmente via cURL em `Api.php`
- `guzzlehttp/guzzle` — não usado (cURL nativo)
- `monolog/monolog` — não usado (logModuleCall do WHMCS)
- `ramsey/uuid` — não usado (random_bytes + hash)

**Risco:** Dependências não utilizadas aumentam a superfície de ataque sem benefício. Se vulnerabilidades forem descobertas nessas libs, o módulo será afetado desnecessariamente.

**Recomendação:** Remover dependências não utilizadas do `require`.

---

### INFO-02: Token de Acesso em Header HTTP com Prefixo Incorreto

**Arquivo:** `modules/gateways/seixastec_mercadopago/Api.php`, linha 463

```php
'Authorization: *** ' . $this->accessToken,
```

O prefixo `***` parece ser um placeholder de mascaramento que foi deixado no código. O formato correto para a API do MP é `Bearer <token>`. Se `***` for realmente enviado, a autenticação falhará. Se for um artifact de log, o código está correto mas é confuso.

**Verificar:** Confirmar se o header enviado é `Authorization: Bearer <token>`.

---

## PONTOS POSITIVOS (Boas Práticas Identificadas)

| Prática | Local |
|---------|-------|
| `declare(strict_types=1)` em todos os arquivos | Todos |
| Guard `if (!defined('WHMCS')) die()` | Todos |
| SSL verification estrita (`VERIFYPEER=true`, `VERIFYHOST=2`) | `Api.php:498-499` |
| HMAC-SHA256 com `hash_equals()` (timing-safe) | `callback:419-421` |
| Anti-replay com validação de timestamp (±5min) | `callback:404-409` |
| Idempotency-Key por operação lógica | `Api.php:382-384` |
| Recalculo server-side do valor em `process.php` | `process.php:153-170` |
| Tolerância de R$0,02 contra adulteração de amount | `process.php:163` |
| Validação de propriedade da fatura (`userid = clientId`) | `pay.php:64-66`, `process.php:124-127` |
| Mascaramento de credenciais em logs | `Api.php:634-641` |
| `htmlspecialchars()` na maioria das saídas | Múltiplos |
| `rel="noopener noreferrer"` em links externos | Templates |
| CPF/CNPJ com validação matemática completa | `Validator.php` |
| Cleanup de arquivos temporários em `finally` | `seixastec_mercadopago_pdf.php:434-442` |
| Retry com backoff exponencial + jitter | `Api.php:547-558` |

---

## Prioridade de Correção

| # | Vulnerabilidade | Severidade | Esforço |
|---|----------------|-----------|---------|
| 1 | VULN-01: Webhook sem HMAC obrigatório | **CRITICAL** | Baixo |
| 2 | VULN-02: Callback não valida valor vs fatura | **CRITICAL** | Baixo |
| 3 | VULN-03: XSS em alert.tpl ($message) | **HIGH** | Baixo |
| 4 | VULN-04: CSRF via GET no sync admin | **HIGH** | Médio |
| 5 | VULN-05: payment_method_id sem whitelist | **HIGH** | Baixo |
| 6 | VULN-06: Race condition no callback | **HIGH** | Médio |
| 7 | VULN-13: escape_html=false global | **MEDIUM** | Baixo |
| 8 | VULN-07: XSS $qrCodeBase64 sem escape | **MEDIUM** | Baixo |
| 9 | VULN-11: CSRF token real em process.php | **MEDIUM** | Médio |
| 10 | VULN-08: SRI nos CDNs | **MEDIUM** | Baixo |

---

*Relatório gerado por auditoria manual de código-fonte. Recomenda-se complementar com análise dinâmica (DAST) e teste de penetração.*
