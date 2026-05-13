# 📋 Changelog

Todas as mudanças notáveis deste projeto serão documentadas neste arquivo.

O formato é baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) e este projeto adere ao [Semantic Versioning](https://semver.org/lang/pt-BR/).

---

## [2.3.0] — 2026-05-12

### ✨ Adicionado

- **Templates Smarty customizáveis** — separação completa de HTML e PHP:
  - Novo `modules/gateways/seixastec_mercadopago/TemplateRenderer.php` (v1.0.0) com cache de instância Smarty.
  - **Override automático por tema do cliente**: `templates/{tema}/seixastec_mercadopago/{template}.tpl` tem prioridade sobre o padrão do módulo.
  - 7 templates entregues: `checkout_pro.tpl`, `pix.tpl`, `boleto.tpl`, `choice.tpl`, `alert.tpl`, `existing_approved.tpl`, `assets.tpl`.
- **Sistema de 8 hooks WHMCS** em `includes/hooks/seixastec_mercadopago.php` (v1.0.0):
  - `DailyCronJob` — sincronização diária + cancelamento de Pix/boleto expirados.
  - `InvoicePaid` — cancela pagamentos pendentes no MP se a fatura foi paga por outro meio.
  - `InvoiceCancelled` — cancela pagamentos pendentes no MP.
  - `InvoiceCreation` — valida dados do cliente para modo boleto.
  - `ClientAreaPageViewInvoice` — injeta CSS auxiliar na fatura.
  - `AdminInvoicesControlsOutput` — botão **"Sincronizar com Mercado Pago"** no admin.
  - `AdminAreaHeadOutput` — avisos visuais de configuração (webhook secret ausente, modo sandbox).
  - `ClientAreaPrimarySidebar` — aviso de Pix pendente para o cliente.
- **README profissional** com seções dedicadas: instalação, configuração, fluxo (diagrama Mermaid), customização de templates, troubleshooting e roadmap.
- Helper `_seixastec_mp_sync_invoice()` reutilizável entre cron e admin.

### 🚀 Modificado

- Funções de renderização do módulo principal (`seixastec_mercadopago.php` → **v2.3.0**) refatoradas para usar `TemplateRenderer::render()`:
  - `_seixastec_mp_render_checkout_pro()`
  - `_seixastec_mp_pix_html()`
  - `_seixastec_mp_boleto_html()`
  - `_seixastec_mp_render_pix_boleto()`
  - `_seixastec_mp_render_existing()`
  - `_seixastec_mp_alert()`
- Cache de configuração do gateway via estática em `_seixastec_mp_gateway()` (evita queries duplicadas em múltiplos hooks).
- CSS centralizado em `assets.tpl` (responsivo via `@media`).

### 🔒 Segurança

- Documentadas todas as camadas de segurança no `README.md`:
  - Validação HMAC-SHA256 com `hash_equals()` (timing-safe).
  - Anti-replay (janela de ±5 minutos no timestamp da assinatura).
  - Mascaramento de Access Token em todos os logs (`logModuleCall`).

---

## [2.2.0] — 2026-05-12

### ✨ Adicionado

- **`modules/gateways/callback/seixastec_mercadopago.php` (v2.2.0)** — webhook handler completo e seguro:
  - Validação **HMAC-SHA256** oficial do Mercado Pago via header `x-signature`.
  - Template assinado conforme spec MP: `id:{data.id};request-id:{x-request-id};ts:{timestamp};`
  - Anti-replay com janela de **±5 minutos** no timestamp.
  - Comparação timing-safe via `hash_equals()`.
  - Suporte a topic `merchant_order.*` com resolução automática para os `payment_id` agregados.
  - Captura de headers compatível (Apache + Nginx + PHP-FPM) via `getallheaders()` com fallback `$_SERVER`.
- Tratamento explícito de status: `approved`, `refunded`, `charged_back`, `cancelled`, `rejected`, `pending`, `in_process`, `authorized`.
- Detecção de **reembolso parcial** via `transaction_amount_refunded > 0`.
- Notas administrativas acumulativas em `tblinvoices.notes` com timestamp (refunds, chargebacks).
- Loop de processamento isolado com `try/catch` por payment (1 erro não derruba os outros).

### 🚀 Modificado

- HTTP **200** sempre após processamento bem-sucedido (mesmo em status não-aprovado) — evita retentativas infinitas do MP.
- HTTP **401** apenas quando assinatura inválida — sinaliza tentativa de fraude.
- HTTP **503** apenas quando gateway desativado/sem token — permite MP retentar depois.

### 🔒 Segurança

- Validação HMAC desabilitada apenas se `webhookSecret` vazio (com warning explícito no log).
- Sanitização de `payment_id` antes de chamadas à API.

---

## [1.7.0] — 2026-05-12

### ✨ Adicionado

- **`modules/gateways/seixastec_mercadopago/Api.php` (v1.7.0)** — cliente HTTP completo para a API Mercado Pago:
  - Namespace PSR-4: `WHMCS\Module\Gateway\SeixastecMercadoPago\Api`.
  - Construtor com **named arguments** (PHP 8+).
  - Métodos principais:
    - `createPreference()` — Checkout Pro.
    - `createPayment()` — Pix/boleto direto.
    - `getPayment()` — consulta de pagamento.
    - `getMerchantOrder()` — resolução de orders agregadas.
    - `refundPayment()` — reembolso total/parcial.
    - `cancelPayment()` — cancelamento de pendentes.
    - `searchPaymentsByExternalReference()` — busca por ID da fatura.
- **Retries com backoff exponencial** (1s, 2s, 4s) para erros 5xx e timeouts.
- **Idempotency-Key** automático via SHA256 do payload.
- **Header `X-Product-Id`** configurável (identificação da aplicação).
- Detecção automática de **sandbox** via prefixo do token (`TEST-`).
- Tratamento robusto de erros com `getLastError()`.
- Mascaramento de tokens em todos os logs (via parâmetro `replaceVars` do `logModuleCall`).

### 🔒 Segurança

- `CURLOPT_SSL_VERIFYPEER` e `CURLOPT_SSL_VERIFYHOST` sempre habilitados.
- Timeout de conexão (10s) e total (30s).
- Headers sensíveis (`Authorization: Bearer`) nunca expostos em logs.

---

## [Não publicado]

### 🛣️ Roadmap

- [ ] `install.php` com migration para tabela própria de auditoria (`mod_seixastec_mp_log`).
- [ ] `composer.json` com PSR-4 autoload + dev dependencies (PHPStan nível 8, PHP-CS-Fixer).
- [ ] `.gitignore` e `.editorconfig`.
- [ ] `CONTRIBUTING.md` e `SECURITY.md`.
- [ ] GitHub Actions com CI (lint + análise estática + testes).
- [ ] Cartão tokenizado via Checkout Transparente.
- [ ] Assinaturas recorrentes (recurring payments).
- [ ] Dashboard de métricas no admin.
- [ ] Multi-conta MP (vários access tokens por produto/cliente).
- [ ] Split payments para marketplaces.
- [ ] Pasta `docs/` com guias por método (Pix, Boleto, troubleshooting avançado).

---

## 📌 Convenções

Este changelog segue o padrão [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/):

| Categoria | Quando usar |
|-----------|-------------|
| **✨ Adicionado** | Novas funcionalidades |
| **🚀 Modificado** | Mudanças em funcionalidades existentes |
| **⚠️ Descontinuado** | Funcionalidades que serão removidas |
| **🗑️ Removido** | Funcionalidades já removidas |
| **🐛 Corrigido** | Correções de bugs |
| **🔒 Segurança** | Correções de vulnerabilidades |

### Versionamento Semântico

| Tipo | Quando incrementar | Exemplo |
|------|--------------------|---------|
| **MAJOR** (X.0.0) | Mudanças incompatíveis na API | `2.3.0 → 3.0.0` |
| **MINOR** (0.X.0) | Funcionalidades retrocompatíveis | `2.2.0 → 2.3.0` |
| **PATCH** (0.0.X) | Correções retrocompatíveis | `2.3.0 → 2.3.1` |

---

## 🔗 Links

- 🐛 [Reportar bug](https://github.com/eseixas/mercadopago-whmcs/issues/new?labels=bug)
- 💡 [Sugerir feature](https://github.com/eseixas/mercadopago-whmcs/issues/new?labels=enhancement)
- 📖 [Documentação completa](README.md)

---

**Mantenedor:** [@eseixas](https://github.com/eseixas)
**Repositório:** [mercadopago-whmcs](https://github.com/eseixas/mercadopago-whmcs)
