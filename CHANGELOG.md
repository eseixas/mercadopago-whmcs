### Documentação
- **`README.md`**: adicionada seção completa **"🎯 Variáveis Disponibilizadas pelo Hook"** documentando todas as variáveis Smarty injetadas pelo `seixastec_mercadopago_pdf.php`:
  - Tabela com 8 variáveis do **PDF da fatura** (`InvoicePdfVars`).
  - Tabela com 5 variáveis dos **e-mails** (`EmailPreSend`).
  - Tabela com 6 variáveis da **área do cliente** (`ClientAreaPageViewInvoice`).
  - Exemplos práticos de uso em `invoicepdf.tpl`, templates de e-mail e `viewinvoice.tpl`.
  - Resumo rápido com convenções de nomenclatura (snake_case vs camelCase).
- Atualizado badge de versão para **1.6.0** no cabeçalho.
- Acrescentadas novas linhas na seção **Segurança** documentando HMAC-SHA256, file lock e escape de variáveis.

## [2.2.0] - 2026-05-12

### Melhorado
- **`modules/gateways/callback/seixastec_mercadopago.php`**: 
  - Função `mp_update_transaction_data()` validada e otimizada para extração correta de dados de **PIX** (QR Code + Copia e Cola) e **Boleto** (URL + linha digitável).
  - Fluxo de persistência de dados da transação agora é chamado de forma mais clara e no momento correto (após validação da fatura).
  - Melhor tratamento de casos onde o método de pagamento é PIX ou Boleto.

- **Revisão técnica completa do módulo**:
  - Confirmado que a lógica de salvamento de dados PIX/Boleto já estava bem implementada no webhook.
  - Reforçada a importância da criação automática da tabela `mod_seixastec_mp_transactions`.
  - Adicionada recomendação de função `_activate()` no gateway principal como camada adicional de garantia (além do hook de instalação existente).

### Documentação
- Atualizado processo de revisão e melhorias no fluxo de dados entre webhook e hook do PDF/e-mail.
- Reforçada documentação interna sobre o local exato de atualização dos dados de pagamento.

## [2.1.1] - 2026-05-11

### Corrigido
- **`modules/gateways/seixastec_mercadopago/pay.php`**: nomes de campos de configuracao (`accessToken` → `accessTokenProd`, `sandboxAccessToken` → `accessTokenSandbox`, `taxaPercentual` → `feePercent`, `publicKey` → `publicKeyProd`, `sandboxPublicKey` → `publicKeySandbox`).
- **`modules/gateways/seixastec_mercadopago/process.php`**: nomes de campos de configuracao (`accessToken` → `accessTokenProd`, `sandboxAccessToken` → `accessTokenSandbox`, `taxaPercentual` → `feePercent`).
- **`modules/gateways/seixastec_mercadopago/process.php`**: removidas colunas `currency` e `raw_payload` do `storeTransaction()` que nao existiam no schema da tabela.
- **`includes/hooks/seixastec_mp_cleanup.php`**: corrigido caminho dos arquivos de lock (`whmcs_mp_locks/mp_txn_` → `mp_payment_` na raiz do temp dir).
- **`LICENSE`**: resolvidos conflitos de merge do Git (marcadores `<<<HEAD` / `>>>branch`) e restaurada GPL-3.0 limpa.
- **`README.md`**: badge de versao atualizado (`1.0.3` → `2.1.0`). Referencia de licenca corrigida (MIT → GPL-3.0).
- **`modules/gateways/seixastec_mercadopago/whmcs.json`**: licenca corrigida (MIT → GPL-3.0).

### Adicionado
- **`modules/gateways/seixastec_mercadopago.php`**: novos campos no `_config()`:
  - `publicKeyProd` / `publicKeySandbox` — Public Keys para o Payment Brick.
  - `paymentMethods` — dropdown para selecionar metodos exibidos no Brick.
  - `maxInstallments` — numero maximo de parcelas.
  - `pixExpiration` — tempo de expiracao do QR Code PIX (minutos).

## [2.1.0] - 2026-05-11

### Alterado
- **`modules/gateways/seixastec_mercadopago.php`**: gateway principal v2.1.0.
  - ➕ Nova seção **"🔔 Webhook (Notificações)"** no painel admin com URL pronta para cópia.
  - ➕ Campo **`webhookSecret`** (password) para validação HMAC-SHA256.
  - ➕ Helper **`seixastec_mercadopago_getWebhookUrl()`** monta URL via `SystemURL`.
  - 🔒 Coerção defensiva de tipos: `(int) $params['clientdetails']['userid']`.
  - 🧹 Fallback `?? ''` em `preg_replace` (evita warnings PHP 8.1+).
  - 📋 Cabeçalho PHPDoc completo com fluxo de uso.

### Adicionado
- **`includes/hooks/seixastec_mp_install.php`**: hook de instalação automática v1.0.0.
  - 🪝 Hook **`AfterModuleActivate`**: cria tabela na ativação do gateway.
  - 🪝 Hook **`AdminAreaPage`**: auto-heal (máx 1x/dia) com `static $checked`.
  - 📋 Sistema de versionamento via `SEIXASTEC_MP_SCHEMA_VERSION` (atual: 2).
  - 🗄 Versão instalada armazenada em `tblconfiguration` (`seixastec_mp_schema_version`).
  - 📊 Função pública **`seixastec_mp_getInstallStatus()`** para diagnóstico.
  - 🔄 Migrações idempotentes — checam `hasTable()` e índices antes de aplicar.

#### Migration v1 — Tabela `mod_seixastec_mp_transactions`
- 15 colunas: `id`, `invoice_id` (UNIQUE), `preference_id`, `payment_id`, `method`, `status`, `amount`, `amount_refunded`, `pix_qr_base64` (MEDIUMTEXT), `pix_copia_cola`, `boleto_url`, `boleto_linha`, `paid_at`, `created_at`, `updated_at`.
- Engine `InnoDB`, charset `utf8mb4`, collation `utf8mb4_unicode_ci`.
- Comentários SQL em cada coluna para auto-documentação.

#### Migration v2 — Índices de performance
- `idx_mp_payment_id` — lookup no webhook.
- `idx_mp_preference_id` — lookup ao gerar link.
- `idx_mp_status` — filtros em relatórios.
- `idx_mp_created_at` — ordenação temporal.

- **`includes/hooks/seixastec_mercadopago_pdf.php`**: hook de injeção PIX/Boleto v1.0.0.
  - 🪝 Hook **`InvoicePdfGeneration`**: renderiza QR Code + linha digitável no PDF.
  - 🪝 Hook **`EmailPreSend`**: injeta 7 variáveis Smarty em 7 tipos de e-mail.
  - 🪝 Hook **`ClientAreaPageViewInvoice`**: injeta bloco HTML interativo na fatura.

#### Variáveis Smarty disponibilizadas
| Variável | Tipo | Onde está disponível |
|---|---|---|
| `{$mp_pix_qr_base64}` | string base64 | Email + ClientArea |
| `{$mp_pix_copia_cola}` | string | Email + ClientArea |
| `{$mp_boleto_url}` | URL | Email + ClientArea |
| `{$mp_boleto_linha}` | string | Email + ClientArea |
| `{$mp_payment_method}` | string | Email + ClientArea |
| `{$mp_status}` | string | Email + ClientArea |
| `{$mp_payment_id}` | string | Email + ClientArea |
| `{$mp_payment_box}` | HTML | ClientArea apenas |
| `{$mp_has_data}` | boolean | ClientArea apenas |

#### Bloco HTML interativo (ClientArea)
- QR Code PIX 200×200px renderizado inline via `data:image/png;base64,...`.
- Textarea com código Copia e Cola + botão "Copiar" com JS (`navigator.clipboard` + fallback `execCommand`).
- Feedback visual "✓ Copiado!" por 2 segundos.
- Linha digitável do boleto em monoespaçado bold com `onclick="this.select()"`.
- Botão "Baixar Boleto" com cor oficial MP (`#009ee3`).
- Renderização condicional: PIX e Boleto exibidos só se houver dados.
- Bloco aparece apenas em faturas `unpaid`, `overdue` ou `draft`.

#### Renderização no PDF (TCPDF)
- QR Code extraído de base64 via `tempnam()` + `file_put_contents()`.
- Tamanho: 35×35mm no rodapé.
- Limpeza automática do arquivo temporário com `@unlink`.
- Cor oficial MP (`SetTextColor(0, 158, 227)`) nos títulos.
- Try/catch global previne quebra da geração do PDF.

### Segurança
- `htmlspecialchars(ENT_QUOTES)` em todas as variáveis renderizadas no HTML.
- `target="_blank" rel="noopener"` em links externos (anti tab-nabbing).
- Verificação `hasTable()` antes de queries (suporta instalações fresh).
- Try/catch global em todos os hooks (nunca bloqueia o WHMCS).
- Coerção defensiva de tipos em todos os parâmetros vindos do WHMCS.

### Compatibilidade
- `declare(strict_types=1)` em todos os arquivos.
- Compatível com WHMCS 8.x e 9.x.
- Compatível com PHP 8.1, 8.2, 8.3.
- Compatível com MySQL 5.7+ e MariaDB 10.3+.
- Compatível com TCPDF (gerador padrão do WHMCS).

### Estrutura final do projeto
\`\`\`
mercadopago-whmcs/
├── modules/gateways/
│   ├── seixastec_mercadopago.php              ✅ v2.1.0
│   ├── seixastec_mercadopago/
│   │   ├── Api.php                            ✅ pronto
│   │   └── Validator.php                      ✅ pronto
│   └── callback/
│       └── seixastec_mercadopago.php          ✅ v2.0.0
└── includes/hooks/
    ├── seixastec_mp_install.php               ✅ v1.0.0 (NOVO)
    └── seixastec_mercadopago_pdf.php          ✅ v1.0.0 (NOVO)
\`\`\`

## [2.0.0] - 2026-05-11

### Adicionado
- **`modules/gateways/callback/seixastec_mercadopago.php`**: Webhook/IPN Handler completo.
  - Suporte aos eventos: `payment.created`, `payment.updated`, `merchant_order`.
  - Roteamento por `type` / `topic` com fallback para outros eventos.
  - Extração de `data.id` compatível com formato novo (JSON) e legado (query-string).

### Segurança (7 camadas)
1. **HMAC-SHA256**: validação do header `x-signature` contra `webhookSecret` configurado.
2. **Anti-replay**: rejeita timestamps fora da janela de 5 minutos (`MP_SIGNATURE_MAX_AGE`).
3. **File lock exclusivo** (`flock LOCK_EX`) por `payment_id` com timeout de 10s — bloqueia processamento concorrente.
4. **Re-verificação via API** (`Api::getPayment()`): nunca confia apenas no payload recebido.
5. **Anti-duplicação**: consulta `tblaccounts` antes de chamar `addInvoicePayment()`.
6. **Anti-tampering**: valida `external_reference` (= invoiceid) via `checkCbInvoiceID()`.
7. **Mascaramento em logs**: filtro automático de `access_token`, `webhookSecret`, `Authorization`.

### Status processados
- **`approved`** → `addInvoicePayment()` + atualiza `mod_seixastec_mp_transactions`.
- **`refunded` / `charged_back`** → registra reembolso, atualiza `amount_refunded`.
- **`pending` / `in_process` / `in_mediation`** → loga sem ação.
- **`rejected` / `cancelled`** → loga com `status_detail`.

### Persistência de Dados de Pagamento
- Extrai automaticamente do `payment.point_of_interaction.transaction_data`:
  - PIX QR Code Base64 (`pix_qr_base64`)
  - PIX Copia e Cola (`pix_copia_cola`)
- Extrai do `payment.transaction_details`:
  - Boleto URL (`boleto_url`)
  - Boleto linha digitável (`boleto_linha`)
- Atualiza tabela `mod_seixastec_mp_transactions` via `updateOrInsert` (UPSERT seguro).

### Confiabilidade
- File lock liberado garantidamente via `try/finally` (mesmo em caso de exception).
- Limpeza automática do arquivo de lock após o processamento.
- Spin-lock com `usleep(200ms)` evita busy-wait excessivo.
- Try/catch em todas as operações de banco para não bloquear o webhook.
- Respostas HTTP corretas: 200 (ok), 400 (bad request), 401 (signature), 404 (invoice), 409 (concurrent), 500 (lock), 502 (api), 503 (gateway off).

### Merchant Orders
- Itera todos os pagamentos vinculados à order.
- Reprocessa cada pagamento individualmente (com seu próprio lock).
- Útil quando o cliente paga uma preferência com múltiplas transações.

### Logging
- **`mp_log_callback($level, $message, $context)`** com níveis: DEBUG, INFO, WARN, ERROR, SUCCESS, SECURITY.
- Logs estruturados em JSON no Module Log do WHMCS.
- Mascaramento automático de credenciais sensíveis.
- IP do remetente registrado em eventos de segurança.
- Log adicional via `logActivity()` para pagamentos aprovados e reembolsos.

### Resposta HTTP
- Todas as respostas em JSON: `{"status", "message", "time"}`.
- `Content-Type: application/json; charset=utf-8`.
- Códigos HTTP semânticos para que o MP saiba se deve reenviar a notificação.

### Compatibilidade
- `declare(strict_types=1)`.
- `getallheaders()` com fallback para `$_SERVER` (compatível com FPM, CLI, FastCGI).
- Suporte simultâneo aos formatos legado (IPN query-string) e novo (Webhooks v2 JSON).
- Bootstrap WHMCS via `init.php` + `gatewayfunctions.php` + `invoicefunctions.php`.

### Configuração necessária
- Adicionar campo `webhookSecret` no `_config()` do gateway principal (próxima versão).
- O secret é obtido em: **MP → Suas Integrações → Webhooks → Configurar notificações → Chave secreta**.

## [1.9.0] - 2026-05-11

### Adicionado
- **`modules/gateways/seixastec_mercadopago.php`**: Módulo principal do gateway WHMCS.
  - **`_MetaData()`**: identificação do gateway, API v1.1, desabilita input local de cartão.
  - **`_config()`**: 14 campos organizados em 5 seções visuais (Credenciais, Taxas, Vencimento, Comportamento, Debug).
  - **`_link()`**: gera preferência no MP e renderiza botão "Pagar agora" com taxas aplicadas.
  - **`_refund()`**: handler de reembolso total/parcial via `Api::refundPayment()`.

### Recursos do Painel Admin
- **Credenciais separadas** para Produção e Sandbox (alternância via toggle).
- **Taxas configuráveis**: percentual (%) + fixa (R$) somadas ao valor da fatura.
- **Multa e juros**: respeitam limite legal do CDC (máx. 2% de multa).
- **Dropdown dinâmico de CPF/CNPJ**: carrega automaticamente todos os campos personalizados de cliente do WHMCS via `Capsule`.
- **Validação opcional**: bloqueia checkout se documento for matematicamente inválido (usa `Validator::validate()`).
- **Modo Debug**: ativa logging detalhado de todas as chamadas à API.

### Fluxo de Pagamento
- Busca documento do cliente em `tblcustomfieldsvalues` pelo ID configurado.
- Monta `payer.identification` automaticamente com tipo (CPF/CNPJ) detectado.
- Define `expiration_date_to` baseado no campo "Vencimento padrão".
- `external_reference` = ID da fatura WHMCS (chave de correlação para o webhook).
- `statement_descriptor` derivado do `companyname` (truncado a 22 chars conforme limite MP).
- Persiste `preference_id` em `mod_seixastec_mp_transactions` para uso posterior pelo hook PDF/e-mail.

### Segurança
- `htmlspecialchars()` em todas as URLs e mensagens renderizadas (XSS-safe).
- Validação obrigatória de Access Token antes de qualquer chamada à API.
- Sanitização agressiva de telefone e CEP (`preg_replace('/\D/', ...)`)
- `target="_blank" rel="noopener"` no botão (previne tab-nabbing).
- Falha silenciosa e logada se `tblcustomfieldsvalues` indisponível.
- Try/catch global em `_link()` e `_refund()` impede crash do checkout.

### UX
- Mensagens de erro estilizadas com `alert-danger` do Bootstrap.
- Exibição do "Total com taxas" quando há acréscimo configurado.
- Ícone Font Awesome no botão (`fa-credit-card`).
- Cor oficial do Mercado Pago (`#009ee3`) no botão e nos títulos das seções.

### Compatibilidade
- `declare(strict_types=1)`.
- Autoload manual de `Api.php` e `Validator.php` via `require_once`.
- Namespace correto: `WHMCS\Module\Gateway\SeixastecMercadoPago\{Api, Validator}`.
- Compatível com WHMCS 9.x e PHP 8.3.
- Guard `defined('WHMCS')` previne acesso direto.

## [1.8.0] - 2026-05-11

### Adicionado
- **`modules/gateways/seixastec_mercadopago/Validator.php`**: Validador matemático de CPF/CNPJ.
  - **`validate($doc)`**: detecta tipo automaticamente (11 dígitos = CPF, 14 = CNPJ) e valida.
  - **`validateCpf($doc)`**: algoritmo módulo 11 com pesos 10→2 (1º DV) e 11→2 (2º DV).
  - **`validateCnpj($doc)`**: algoritmo módulo 11 com pesos `[5,4,3,2,9,8,7,6,5,4,3,2]` (1º DV) e `[6,5,4,3,2,9,8,7,6,5,4,3,2]` (2º DV).
  - **`sanitize($doc)`**: remove pontos, traços, barras e espaços.
  - **`detectType($doc)`**: retorna `'CPF'`, `'CNPJ'` ou `'INVALID'`.
  - **`format($doc)`**: aplica máscara brasileira (`000.000.000-00` / `00.000.000/0000-00`).
  - **`mask($doc)`**: oculta dígitos para logs (LGPD): `***.***.***-12`.
  - **`inspect($doc)`**: retorna array completo (`valid`, `type`, `clean`, `formatted`, `masked`).

### Segurança / Compliance
- Rejeita sequências repetidas inválidas (ex.: `111.111.111-11`, `00.000.000/0000-00`) que passariam no cálculo mas são oficialmente inválidas pela Receita Federal.
- **Mascaramento LGPD-friendly** preserva apenas os últimos 2 dígitos para auditoria sem exposição.
- Classe `final` impede extensão maliciosa que pudesse contornar validação.
- Métodos `static` puros (sem estado) — thread-safe e seguros para uso em qualquer contexto.

### Qualidade
- `declare(strict_types=1)` em todo o arquivo.
- Constantes públicas tipadas (`TYPE_CPF`, `TYPE_CNPJ`, `TYPE_INVALID`).
- Uso de `match` expression (PHP 8.x) para legibilidade.
- Documentação PHPDoc completa em todos os métodos públicos.
- Guard `defined('WHMCS')` previne acesso direto via URL.

## [1.7.0] - 2026-05-11

### Adicionado
- **`modules/gateways/seixastec_mercadopago/Api.php`**: Cliente HTTP completo da API v1 do Mercado Pago.
  - **Preferências**: `createPreference()`, `getPreference()` (Checkout Pro).
  - **Pagamentos**: `createPayment()`, `getPayment()`, `searchPaymentsByExternalReference()`, `searchPayments()` (PIX, Boleto, Cartão).
  - **Reembolsos**: `refundPayment()` (total/parcial), `listRefunds()`, `getRefund()`.
  - **Merchant Orders**: `getMerchantOrder()` (agregador de múltiplos pagamentos).
  - **Utilitários**: `getPaymentMethods()`, `testCredentials()` (health check do token).
  - **Inspeção de erros**: `getLastError()`, `getLastHttpCode()`, `getLastResponse()`.

### Confiabilidade
- **Retry automático** com 3 tentativas em códigos HTTP 408/429/500/502/503/504 e erros de rede.
- **Backoff exponencial** com jitter aleatório (0-30%) para evitar thundering herd: 500ms → 1s → 2s.
- **Idempotency-Key** SHA-256 determinístico (método + path + body + janela 1min) em todas as operações POST, garantindo que retries não criem duplicatas.
- **Timeout configurável**: 30s para resposta, 10s para conexão.
- **Tratamento estruturado de erros**: extração automática de `message` + `cause[].description` da resposta do MP.

### Segurança
- **SSL estrito**: `CURLOPT_SSL_VERIFYPEER = true`, `CURLOPT_SSL_VERIFYHOST = 2`.
- **Redirects desabilitados**: `CURLOPT_FOLLOWLOCATION = false` (previne SSRF).
- **Mascaramento em logs**: Access Token, headers `Authorization`/`Bearer` filtrados via `logModuleCall()`.
- **User-Agent identificável** para auditoria do lado do MP.
- **X-Product-Id** fixo para rastreamento da integração no painel do MP.
- Validação obrigatória do Access Token no construtor (`InvalidArgumentException` se vazio).

### Performance
- **Compressão automática** (`gzip, deflate`) habilitada via `CURLOPT_ENCODING`.
- **HTTP/1.1** forçado para compatibilidade máxima.
- **JSON minimizado**: `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES` para payloads compactos.

### Compatibilidade
- Namespace PSR-4: `WHMCS\Module\Gateway\SeixastecMercadoPago\Api`.
- Strict types declarado: `declare(strict_types=1)`.
- Guard `defined('WHMCS')` previne acesso direto.
- Falha silenciosa no `logModuleCall()` quando função não disponível (uso em testes unitários).

## [1.6.0] - 2026-05-11

### Adicionado
- **`includes/hooks/seixastec_mercadopago_pdf.php`**: Hook unificado para injeção de PIX/Boleto em PDF, e-mail e área do cliente.
  - **`InvoicePdfVars`**: variáveis `{$mp_pix_qr_image}`, `{$mp_pix_copia_cola}`, `{$mp_boleto_url}`, `{$mp_boleto_linha}` + blocos HTML prontos (`{$mp_pix_html}`, `{$mp_boleto_html}`) para uso direto no template PDF.
  - **`EmailPreSend`**: injeta bloco HTML estilizado ao final do corpo dos e-mails de fatura (criação e lembretes), com botão CTA para boleto e QR Code renderizado inline (base64).
  - **`ClientAreaPageViewInvoice`**: expõe variáveis ao `viewinvoice.tpl` (`{$mpPixQrImage}`, `{$mpPixCopiaCola}`, `{$mpBoletoUrl}`, `{$mpBoletoLinha}`, `{$mpHasPix}`, `{$mpHasBoleto}`) para customização do template.

### Performance
- Cache em memória por requisição (`static $cache`) evita múltiplas consultas para a mesma fatura quando vários hooks rodam no mesmo ciclo.
- Estratégia de busca em camadas: primeiro a tabela local `mod_seixastec_mp_transactions` (rápido), depois fallback via `Api::searchPaymentsByExternalReference()` (autoritativo).

### UX
- Bloco PIX no e-mail com gradiente Mercado Pago, instruções passo a passo e QR Code 170×170.
- Bloco Boleto com botão CTA destacado (laranja `#f0ad4e`) e linha digitável formatada em monoespaçado.
- Versões PDF dos blocos otimizadas (tipografia compacta, sem CSS moderno incompatível com mPDF/Dompdf).

### Segurança
- Escape rigoroso via `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` em todas as variáveis injetadas no HTML.
- Filtro de e-mails: somente mensagens da whitelist (`Invoice Created`, lembretes, overdue) recebem o bloco.
- Faturas com status `Paid` são ignoradas (não injeta nada).
- Verificação de `paymentmethod === 'seixastec_mercadopago'` antes de processar.

### Compatibilidade
- Detecta automaticamente ausência da tabela `mod_seixastec_mp_transactions` (`hasTable()`) e cai no fallback da API.
- Ignora pagamentos com status `rejected`, `cancelled`, `refunded` ao buscar dados via API.
- Compatível com WHMCS 9.x e versões anteriores que suportem os hooks usados.

## [1.5.0] - 2026-05-11

### Adicionado
- **`modules/gateways/callback/seixastec_mercadopago.php`**: Webhook IPN handler completo e consolidado.
  - Suporte a notificações `payment` e `merchant_order` (com agregação de múltiplos payments).
  - Validação HMAC-SHA256 da assinatura do header `x-signature` (consumindo `webhookSecret` configurado no admin).
  - Proteção contra replay attack: tolerância de 5 minutos no `ts` da assinatura.
  - File lock exclusivo (`flock LOCK_EX | LOCK_NB`) por `payment_id` em `tmp/seixastec_mp_locks/` contra race condition entre webhooks simultâneos.
  - Verificação dupla de idempotência: status `Paid` da fatura **e** existência prévia em `tblaccounts`.
  - Verificação autoritativa do pagamento via `Api::getPayment()` antes de registrar (não confia no payload bruto).
  - Atualização da tabela local `mod_seixastec_mp_transactions` independentemente do status (auditoria).
  - Suporte a payload via JSON body **e** query string (formato legado do MP).
  - Tradução automática de `merchant_order` URLs em IDs numéricos.
  - Tratamento de notificações `test` para health check.

### Segurança
- Headers sensíveis filtrados nos logs (`accessToken`, `webhookSecret`, `access_token`).
- `hash_equals()` para comparação de assinatura (resistente a timing attack).
- Locks armazenados fora do webroot (`tmp/seixastec_mp_locks/`) com permissão `0750`.
- Liberação garantida do lock via bloco `finally`.
- Limpeza automática do arquivo de lock após processamento.

### Observabilidade
- Modo debug detalhado registra headers, query e body do webhook.
- Logs estruturados por categoria: `PAYMENT_FETCHED`, `INVOICE_ALREADY_PAID`, `LOCK_BUSY`, etc.
- Resposta HTTP sempre informativa (200 com mensagem descritiva para evitar retry excessivo do MP).

### Compatibilidade
- Suporte a `payment`, `payment.created`, `payment.updated` e `merchant_order`.
- Fallback gracioso quando `webhookSecret` não está configurado (loga aviso e processa).
- Compatível com formato legado de notificação via `?topic=&id=`.

## [1.4.0] - 2026-05-11

### Adicionado
- **`modules/gateways/seixastec_mercadopago/process.php`**: Endpoint AJAX para processamento de pagamentos vindos do Payment Brick.
  - Suporte completo para **PIX** (com QR Code Base64 + Copia e Cola).
  - Suporte completo para **Boleto** (com linha digitável + URL externa).
  - Suporte completo para **Cartão de Crédito e Débito** (token + parcelas + issuer).
  - Idempotência determinística por fatura/método/valor (`inv-{id}-{method}-{md5}`).
  - Persistência automática em `mod_seixastec_mp_transactions` (auditoria).
  - Registro imediato no `tblaccounts` quando o cartão é aprovado na hora.
  - Tradução de `status_detail` do MP em mensagens amigáveis ao cliente.
  - Enriquecimento automático do CPF/CNPJ via custom field do WHMCS quando o Brick não envia.

### Segurança
- Recálculo **autoritativo** do `amount` no servidor (tolerância R$ 0,02 contra adulteração do valor enviado pelo Brick).
- Validação de sessão (`$_SESSION['uid']`) e propriedade da fatura (`tblinvoices.userid`).
- Header `X-Requested-With: XMLHttpRequest` obrigatório (mitigação CSRF básica).
- Headers de segurança: `X-Content-Type-Options: nosniff`, `Cache-Control: no-store`.
- Mascaramento de campos sensíveis nos logs (`accessToken`, `token`, `security_code`, `card_number`).
- Bloqueio de faturas com status `Cancelled` ou `Refunded`.
- Validação rigorosa do payload JSON com `json_last_error()`.

### Detalhes técnicos
- Uso de `respond(): never` (PHP 8.1+) para retornos terminais.
- `match` expression (PHP 8.0+) para mapeamento de `status_detail`.
- Endereço do pagador auto-preenchido para boletos (requisito do MP).
- Truncamento automático do `statement_descriptor` em 22 caracteres (limite MP).

## [1.3.0] - 2026-05-11

### Adicionado
- **`modules/gateways/seixastec_mercadopago/pay.php`**: Página de checkout responsiva com Mercado Pago Payment Brick (SDK v2).
  - Renderização dinâmica de métodos (PIX, Cartão, Débito, Boleto) conforme configuração.
  - Aplicação automática de taxa adicional (`taxaPercentual`) sobre o valor da fatura.
  - Auto-preenchimento dos dados do pagador (nome, e-mail) a partir do `tblclients`.
  - Leitura de CPF/CNPJ via custom field (`CPF`, `CNPJ`, `CPF/CNPJ`, `Documento`).
  - Aviso visual de **Modo Sandbox** quando ativo.
  - Redirecionamento automático se a fatura já estiver paga.

### Segurança
- Validação de sessão WHMCS (`$_SESSION['uid']`) antes de exibir o checkout.
- Verificação de propriedade da fatura (`tblinvoices.userid = $clientId`).
- Bloqueio de faturas com status `Cancelled` ou `Refunded`.
- Escape de output via `htmlspecialchars()` e `json_encode()` em todos os pontos sensíveis.
- Meta tag `noindex,nofollow` para evitar indexação da página.

### UI/UX
- Design responsivo com Bootstrap 5.3.2 e Font Awesome 6.4.2.
- Header com gradiente Mercado Pago e indicadores de segurança SSL.
- Loader animado enquanto o Brick é inicializado.

## [1.0.3] - 2026-04-27

### Changed
- Renomeado o módulo de `mercadopago` para `seixastec_mercadopago` para evitar conflitos de nome e refletir a marca.
- Atualizada toda a estrutura de arquivos e diretórios: `seixastec_mercadopago.php`, diretório `seixastec_mercadopago/`, callback `seixastec_mercadopago.php` e hook `seixastec_mercadopago_pdf.php`.
- Atualizados os nomes das funções internas (ex: `seixastec_mercadopago_link`) e o namespace para `WHMCS\Module\Gateway\SeixastecMercadoPago`.

## [1.0.2] - 2026-03-23

### Fixed
- Corrigida race condition (TOCTOU) no callback de webhook que permitia pagamentos duplicados quando múltiplos webhooks chegavam simultaneamente. Implementado file lock exclusivo (`flock LOCK_EX`) por transaction ID para serializar o processamento.
- Adicionada verificação de status do invoice: webhooks são ignorados se o invoice já estiver marcado como "Paid", evitando geração de crédito indevido.

## [1.0.1] - 2026-03-18

### Fixed
- Corrigido processamento duplicado de webhooks: o callback agora verifica `tblaccounts` antes de registrar pagamentos, evitando cobranças duplicadas e múltiplos emails de confirmação.
- Corrigida a geração da chave de idempotência na API: removido `time()` que tornava cada chave única, derrotando o propósito da idempotência.
- Corrigida informação incorreta na seção de segurança do README: o WHMCS **não** verifica duplicidade de `transid` nativamente.

## [1.0.0] - 2026-03-18

### Added
- Initial release of the Mercado Pago WHMCS Gateway module.
- Support for PIX (QR Code + Copia e Cola).
- Support for Boleto Bancário (Linha Digitável).
- Support for Cartão de Crédito and Cartão de Débito via Checkout Pro.
- Automatic payment confirmation via Mercado Pago Webhooks (IPN).
- JavaScript polling on the invoice page for instant status updates after PIX payment.
- Full and partial refund support triggered directly from the WHMCS admin.
- PIX QR Code and Boleto Linha Digitável injected into invoice PDF variables.
- PIX Copia e Cola and Boleto info embedded in invoice HTML emails.
- Configurable percentage and fixed fee surcharges per invoice.
- Boleto late fine (multa) capped at 2% as per CDC Art. 52 §1º (Lei 8.078/90).
- Proportional daily interest (juros) based on a configurable monthly rate.
- Configurable boleto expiration (days from today).
- Dynamic dropdown for CPF/CNPJ custom field selection (reads `tblcustomfields` via Capsule).
- CPF and CNPJ validation with math-based digit verification.
- Sandbox / Production mode toggle.
- Enhanced module call logging with access token masking.
- External Reference set to WHMCS Invoice ID for easy payment tracing on the MP dashboard.