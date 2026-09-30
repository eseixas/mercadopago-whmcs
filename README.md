<div align="center">

# 💳 Mercado Pago Gateway for WHMCS

**Módulo de pagamento completo e moderno do Mercado Pago para WHMCS**

Aceite pagamentos via **Pix**, **Cartão de Crédito**, **Boleto** e **Saldo Mercado Pago** de forma segura, automatizada e com confirmação em tempo real via webhook.

[![CI](https://github.com/eseixas/mercadopago-whmcs/actions/workflows/ci.yml/badge.svg)](https://github.com/eseixas/mercadopago-whmcs/actions/workflows/ci.yml)
[![Latest Release](https://img.shields.io/github/v/release/eseixas/mercadopago-whmcs?include_prereleases&sort=semver)](https://github.com/eseixas/mercadopago-whmcs/releases)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-8892BF.svg)](https://www.php.net/)
[![WHMCS](https://img.shields.io/badge/WHMCS-%3E%3D8.10-2563EB.svg)](https://www.whmcs.com/)
[![License: GPL-3.0](https://img.shields.io/badge/License-GPL--3.0-blue.svg)](LICENSE)
[![GitHub Stars](https://img.shields.io/github/stars/eseixas/mercadopago-whmcs?style=social)](https://github.com/eseixas/mercadopago-whmcs/stargazers)

[**📦 Instalação**](#-instalação) •
[**⚙️ Configuração**](#️-configuração) •
[**📖 Documentação**](#-documentação) •
[**🐛 Reportar Bug**](https://github.com/eseixas/mercadopago-whmcs/issues/new?template=bug_report.md) •
[**💡 Sugerir Feature**](https://github.com/eseixas/mercadopago-whmcs/issues/new?template=feature_request.md)

</div>


## Atualização de segurança 2.4.2

O checkout Brick envia um token CSRF próprio no campo JSON `csrf_token`, separado do token do cartão. O endpoint exige a sessão autenticada, o header AJAX, o token dessa sessão e uma fatura do cliente. O token permanece válido entre abas e retries; checkouts abertos antes da atualização devem ser recarregados.

Para atualizar da 2.4.1, faça backup fora do webroot e instale juntos `CheckoutCsrf.php`, `pay.php` e `process.php`, mais a identificação de versão em `modules/gateways/seixastec_mercadopago.php` e `modules/gateways/seixastec_mercadopago/whmcs.json`. Preserve suas configurações e customizações. Não é necessário desativar o gateway, trocar credenciais ou migrar o banco. O schema existente permanece na versão 3.

Compatibilidade declarada: PHP 8.2+ e WHMCS 8.10+/9.x. Testado localmente com PHP 8.3.33, mocks e nenhum pagamento real. Não foi comprovada exploração em produção; aliases, proxies, CORS e integrações de browser/WHMCS precisam de validação na instalação.

---

## 📑 Índice

- [✨ Funcionalidades](#-funcionalidades)
- [📋 Requisitos](#-requisitos)
- [📦 Instalação](#-instalação)
- [⚙️ Configuração](#️-configuração)
- [🔔 Webhook (IPN)](#-webhook-ipn)
- [🧪 Modo Sandbox](#-modo-sandbox)
- [🎨 Personalização](#-personalização)
- [🔧 Troubleshooting](#-troubleshooting)
- [📖 Documentação](#-documentação)
- [🛠️ Desenvolvimento](#️-desenvolvimento)
- [🤝 Contribuindo](#-contribuindo)
- [🔒 Segurança](#-segurança)
- [📝 Changelog](#-changelog)
- [📄 Licença](#-licença)
- [💖 Apoie o Projeto](#-apoie-o-projeto)

---

## ✨ Funcionalidades

### 💰 Métodos de Pagamento

| Método | Status | Confirmação | Observações |
|--------|:------:|:-----------:|-------------|
| 🟢 **Pix** | ✅ | Instantânea | QR Code + Copia e Cola |
| 💳 **Cartão de Crédito** | ✅ | Instantânea | Parcelamento em até 12x |
| 📄 **Boleto Bancário** | ✅ | 1-2 dias úteis | Vencimento configurável |
| 💵 **Saldo Mercado Pago** | ✅ | Instantânea | Para clientes com conta MP |

### 🚀 Recursos Principais

- ✅ **Checkout Transparente** — cliente paga sem sair do seu site
- ✅ **Checkout Pro (Redirect)** — opção de redirecionar para o ambiente MP
- ✅ **Webhook (IPN) automático** — baixa de faturas em tempo real
- ✅ **Reconciliação automática** de pagamentos via cron
- ✅ **Suporte multi-moeda** (BRL, ARS, MXN, CLP, COP, UYU, PEN)
- ✅ **Logs detalhados** integrados ao Gateway Log do WHMCS
- ✅ **Idempotência** em todas as requisições (evita cobrança duplicada)
- ✅ **Validação HMAC** de assinatura do webhook (segurança máxima)
- ✅ **Compatível com WHMCS 8.6+** (incluindo 8.13 e versões futuras)
- ✅ **PHP 8.1, 8.2, 8.3, 8.4** suportados
- ✅ **PSR-4 autoload** + arquitetura limpa (SOLID)
- ✅ **Internacionalização** (pt_BR, en_US, es_ES)
- ✅ **Tema dark/light** no order form
- ✅ **Refunds (estornos)** direto do admin WHMCS

---

## 📋 Requisitos

### Ambiente

| Software | Versão Mínima | Versão Recomendada |
|----------|:-------------:|:------------------:|
| **WHMCS** | 8.6 | 8.13+ |
| **PHP** | 8.1 | 8.3 |
| **MySQL / MariaDB** | 5.7 / 10.3 | 8.0 / 10.11 |
| **cURL** | 7.68 | 8.x |

### Extensões PHP obrigatórias

```bash
php-curl  php-json  php-openssl  php-mbstring  php-bcmath
```

### Mercado Pago

- ✅ Conta Mercado Pago ativa e verificada
- ✅ **Access Token** de produção ([obtenha aqui](https://www.mercadopago.com.br/developers/panel/credentials))
- ✅ **Public Key** de produção
- ✅ URL do seu WHMCS acessível publicamente (HTTPS obrigatório)

---

## Instalação

1. Baixe o ZIP do [Release 2.4.2](https://github.com/eseixas/mercadopago-whmcs/releases/tag/v2.4.2) e confira o SHA-256 publicado.
2. Extraia a pasta `mercadopago-whmcs/`. Copie **somente** suas pastas `modules/` e `includes/` para a raiz do WHMCS, mantendo esta estrutura:

```text
modules/gateways/seixastec_mercadopago.php
modules/gateways/seixastec_mercadopago/        # classes, checkout e templates
modules/gateways/callback/seixastec_mercadopago.php
includes/hooks/seixastec_mercadopago.php
includes/hooks/seixastec_mercadopago_pdf.php
includes/hooks/seixastec_mp_install.php
includes/hooks/seixastec_mp_cleanup.php
```

3. Mantenha proprietário/permissões compatíveis com o usuário do PHP no servidor. Não publique testes, scripts de deploy, arquivos `.env`, relatórios locais ou dependências de desenvolvimento.
4. No admin, abra os gateways e configure **Mercado Pago (SeixasTec)**. Em uma instalação nova, confira as tabelas e hooks seguindo o fluxo existente do módulo.
5. Para atualização, use o procedimento 2.4.1 → 2.4.2 acima, confira `php -l` e mantenha o backup para rollback. Validar telas e rejeições não exige criar pagamentos.

---

## ⚙️ Configuração

### 1️⃣ Obter credenciais do Mercado Pago

1. Acesse o [Painel de Desenvolvedores do Mercado Pago](https://www.mercadopago.com.br/developers/panel/credentials)
2. Crie uma aplicação (ou use uma existente)
3. Copie o **Access Token** e a **Public Key** (use as de **produção**)

### 2️⃣ Ativar o gateway no WHMCS

1. Acesse **Setup → Payments → Payment Gateways**
2. Clique em **All Payment Gateways**
3. Encontre **Mercado Pago** e clique para ativar
4. Configure os campos:

| Campo | Descrição | Exemplo |
|-------|-----------|---------|
| **Show on Order Form** | Nome exibido ao cliente | `Mercado Pago` |
| **Access Token** | Token de produção | `APP_USR-1234...` |
| **Public Key** | Chave pública | `APP_USR-abcd...` |
| **Webhook Secret** | Chave do webhook (HMAC) | `sua_chave_secreta` |
| **Modo Sandbox** | Ativar modo de testes | `☐ Desativado` |
| **Métodos aceitos** | Pix, Cartão, Boleto | `☑ Todos` |
| **Máximo de parcelas** | Para cartão de crédito | `12` |
| **Juros nas parcelas** | A partir de qual parcela | `2` |
| **Dias de vencimento (Boleto)** | Validade do boleto | `3` |
| **Expiração (Pix)** | Tempo de validade do QR | `30` (minutos) |
| **Logs detalhados** | Habilitar gateway log | `☑ Ativado` |

5. Clique em **Save Changes**

### Cron do WHMCS

Mantenha o cron padrão do WHMCS configurado. O módulo usa os hooks `DailyCronJob` para as tarefas previstas; não existe `modules/gateways/mercadopago/cron/check_pending.php` neste repositório. Não execute sincronização de pagamentos como teste de atualização.

---

## 🔔 Webhook (IPN)

### Configuração no Mercado Pago

1. Acesse [Painel MP → Webhooks](https://www.mercadopago.com.br/developers/panel/notifications/webhooks)
2. Adicione a URL:

   ```
   https://seudominio.com/modules/gateways/callback/seixastec_mercadopago.php
   ```

3. Selecione os eventos:
   - ✅ `payment` (pagamentos)
   - ✅ `merchant_order` (ordens)

4. Copie a **chave secreta** gerada e cole no campo **Webhook Secret** do WHMCS.

### Testar o webhook

```bash
# Teste manual (a partir do painel do MP)
curl -X POST https://seudominio.com/modules/gateways/callback/seixastec_mercadopago.php \
  -H "Content-Type: application/json" \
  -H "x-signature: ts=1234567890,v1=..." \
  -d '{"type":"payment","data":{"id":"123456789"}}'
```

✅ Resposta esperada: `HTTP 200 OK`

### Logs do webhook

Acesse: **Utilities → Logs → Gateway Log** e filtre por **Mercado Pago**.

---

## 🧪 Modo Sandbox

Para testar sem cobrar de verdade:

1. Crie um **usuário de teste** no [Painel MP → Test Users](https://www.mercadopago.com.br/developers/panel/test-users)
2. Gere credenciais de teste (Access Token + Public Key começam com `TEST-`)
3. Ative o **Modo Sandbox** no WHMCS
4. Use os [cartões de teste oficiais](https://www.mercadopago.com.br/developers/pt/docs/checkout-api/integration-test/test-cards):

| Bandeira | Número | CVV | Validade |
|----------|--------|:---:|:--------:|
| Mastercard | `5031 4332 1540 6351` | `123` | `11/30` |
| Visa | `4509 9535 6623 3704` | `123` | `11/30` |
| Amex | `3711 803032 57522` | `1234` | `11/30` |

### Resultados forçados (CPF/Nome do titular)

| Resultado | Nome do titular |
|-----------|-----------------|
| ✅ Aprovado | `APRO` |
| ❌ Recusado por erro geral | `OTHE` |
| ⏳ Pendente | `CONT` |
| 🔐 Recusado por código de segurança | `CALL` |
| 💰 Recusado por saldo insuficiente | `FUND` |

---

## 🎨 Personalização

### Customizar o template do checkout

Copie o template padrão e edite:

```bash
mkdir -p templates/seu-tema/seixastec_mercadopago
cp modules/gateways/seixastec_mercadopago/templates/checkout_pro.tpl \
   templates/seu-tema/seixastec_mercadopago/checkout_pro.tpl
```

### Hooks disponíveis

```php
// includes/hooks/mercadopago_custom.php

add_hook('MercadoPagoBeforeCreatePayment', 1, function($vars) {
    // Modificar dados antes de criar o pagamento
    return [
        'metadata' => [
            'origem' => 'site_principal',
            'cliente_id' => $vars['userid'],
        ],
    ];
});

add_hook('MercadoPagoAfterPaymentApproved', 1, function($vars) {
    // Enviar email customizado, integrar com CRM, etc.
    logActivity("Pagamento MP aprovado: {$vars['payment_id']}");
});

add_hook('MercadoPagoWebhookReceived', 1, function($vars) {
    // Processar dados adicionais do webhook
});
```

### Language Overrides

Crie traduções customizadas em:

```
/lang/overrides/portuguese-br.php
```

```php
<?php
$_LANG['mercadopago']['title']       = 'Pague com Mercado Pago';
$_LANG['mercadopago']['pix']         = 'PIX (aprovação imediata)';
$_LANG['mercadopago']['boleto']      = 'Boleto bancário';
$_LANG['mercadopago']['credit_card'] = 'Cartão de crédito';
```

---

## 🔧 Troubleshooting

<details>
<summary><strong>❌ Erro: "Access Token inválido"</strong></summary>

**Causa:** Credenciais incorretas ou expiradas.

**Solução:**
1. Verifique se copiou o token completo (sem espaços)
2. Confirme que está usando token de **produção** (começa com `APP_USR-`)
3. Para testes, use token de **sandbox** (começa com `TEST-`)
4. Regenere o token no painel do MP se necessário

</details>

<details>
<summary><strong>❌ Webhook não está sendo recebido</strong></summary>

**Causas comuns:**
- URL incorreta no painel do MP
- Firewall bloqueando IPs do Mercado Pago
- HTTPS com certificado inválido
- ModSecurity bloqueando POST

**Diagnóstico:**
```bash
# 1. Testar URL manualmente
curl -I https://seudominio.com/modules/gateways/callback/seixastec_mercadopago.php

# 2. Verificar logs do Apache/Nginx
tail -f /var/log/apache2/access.log | grep mercadopago

# 3. Verificar logs do WHMCS
# Utilities → Logs → Gateway Log
```

**IPs do Mercado Pago (libere no firewall):**
```
209.225.49.0/24
216.33.196.0/24
```

</details>

<details>
<summary><strong>❌ Faturas não são baixadas automaticamente</strong></summary>

**Solução:**
1. Verifique se o **cron está ativo**:
   ```bash
   crontab -l | grep mercadopago
   ```
2. Verifique permissões dos arquivos:
   ```bash
   ls -la modules/gateways/seixastec_mercadopago/
   ```
3. Habilite **logs detalhados** no gateway e reproduza o erro
4. Verifique o **Gateway Log** em **Utilities → Logs**

</details>

<details>
<summary><strong>❌ Erro: "Signature inválida no webhook"</strong></summary>

**Causa:** `Webhook Secret` configurado incorretamente.

**Solução:**
1. Acesse [Painel MP → Webhooks](https://www.mercadopago.com.br/developers/panel/notifications/webhooks)
2. Edite a notificação e copie a **chave secreta**
3. Cole no campo **Webhook Secret** do gateway no WHMCS
4. Salve e teste novamente

</details>

<details>
<summary><strong>❌ Erro 500 ao acessar o checkout</strong></summary>

**Diagnóstico:**
```bash
# Verificar error log do PHP
tail -100 /var/log/php-fpm/error.log

# Verificar error log do WHMCS
# Utilities → Logs → Activity Log
```

**Soluções comuns:**
- Aumentar `memory_limit` para `256M` no `php.ini`
- Verificar se todas as extensões PHP estão instaladas
- Reinstalar dependências via Composer

</details>

> 💬 **Não encontrou seu problema?** [Abra uma issue](https://github.com/eseixas/mercadopago-whmcs/issues/new?template=bug_report.md) com logs completos.

---

## 📖 Documentação

| Documento | Descrição |
|-----------|-----------|
| 📘 [Guia de Instalação Completo](docs/INSTALLATION.md) | Tutorial detalhado passo a passo |
| 🔧 [Guia de Configuração](docs/CONFIGURATION.md) | Todas as opções explicadas |
| 🔌 [Referência de Hooks](docs/HOOKS.md) | Lista completa de hooks |
| 🌐 [API Reference](docs/API.md) | Documentação das classes |
| 🔄 [Guia de Migração](docs/MIGRATION.md) | Migrar de outros gateways MP |
| ❓ [FAQ](docs/FAQ.md) | Perguntas frequentes |

### Documentação oficial Mercado Pago

- [Checkout API](https://www.mercadopago.com.br/developers/pt/docs/checkout-api/landing)
- [Webhooks](https://www.mercadopago.com.br/developers/pt/docs/your-integrations/notifications/webhooks)
- [Pix](https://www.mercadopago.com.br/developers/pt/docs/checkout-api/payment-methods/other-payment-methods)

---

## 🛠️ Desenvolvimento

### Setup do ambiente

```bash
# Clonar o repositório
git clone https://github.com/eseixas/mercadopago-whmcs.git
cd mercadopago-whmcs

# Instalar dependências
composer install

# Copiar arquivo de ambiente
cp .env.example .env

# Subir ambiente Docker (opcional)
docker-compose up -d
```

### Scripts disponíveis

```bash
composer test            # Roda PHPUnit
composer test:coverage   # Roda testes com cobertura
composer phpstan         # Análise estática
composer cs:check        # Verifica code style
composer cs:fix          # Corrige code style automaticamente
composer qa              # Roda cs:check, phpstan e tests
```

### Estrutura do projeto

```text
modules/gateways/seixastec_mercadopago.php
modules/gateways/seixastec_mercadopago/
    Api.php, CheckoutCsrf.php, InvoiceAmount.php, TransactionStore.php
    Validator.php, BrazilAddress.php, WebhookSignature.php, TemplateRenderer.php
    constants.php, pay.php, process.php, templates/, whmcs.json
modules/gateways/callback/seixastec_mercadopago.php
includes/hooks/
tests/Unit/
tests/Security/           # regressão HTTP com mocks, sem API real
scripts/build-release.php
composer.json
```

---

## 🤝 Contribuindo

Contribuições são **muito bem-vindas**! 🎉

1. Faça um fork do projeto
2. Crie sua branch: `git checkout -b feature/minha-feature`
3. Commit suas mudanças: `git commit -m 'feat: adiciona X'` ([Conventional Commits](https://www.conventionalcommits.org/))
4. Push para a branch: `git push origin feature/minha-feature`
5. Abra um **Pull Request**

📖 Leia o [**CONTRIBUTING.md**](CONTRIBUTING.md) para diretrizes completas.

### Contribuidores

<a href="https://github.com/eseixas/mercadopago-whmcs/graphs/contributors">
  <img src="https://contrib.rocks/image?repo=eseixas/mercadopago-whmcs" alt="Contribuidores" />
</a>

---

## 🔒 Segurança

Encontrou uma vulnerabilidade de segurança? **Não abra uma issue pública.**

📧 Envie um email para: **security@eseixas.dev**

Mais detalhes em [**SECURITY.md**](SECURITY.md).

---

## 📝 Changelog

Veja o histórico completo de mudanças em [**CHANGELOG.md**](CHANGELOG.md).

Este projeto segue [Semantic Versioning](https://semver.org/lang/pt-BR/) e [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/).

---

## 📄 Licença

Este projeto é licenciado sob a **MIT License** — veja o arquivo [LICENSE](LICENSE) para detalhes.

```
Copyright (c) 2026 Eduardo Seixas
```

> ⚠️ **WHMCS** é marca registrada da WHMCS Limited. Este projeto **não é afiliado oficialmente** à WHMCS Limited nem ao Mercado Pago.

---

## 💖 Apoie o Projeto

Se este projeto te ajudou, considere:

- ⭐ Dar uma **estrela** no GitHub
- 🐛 Reportar **bugs** encontrados
- 💡 Sugerir **melhorias**
- 📢 Compartilhar com outros usuários WHMCS
- ☕ [Pagar um café](https://github.com/sponsors/eseixas) para o mantenedor

---

<div align="center">

**Feito com ❤️ no Brasil 🇧🇷**

[⬆ Voltar ao topo](#-mercado-pago-gateway-for-whmcs)

</div>


## Testes isolados da versão 2.4.2

No checkout de desenvolvimento:

```bash
composer install --no-scripts --no-plugins
composer test -- --no-coverage
composer test:security
composer phpstan
```

`test:security` inicia um servidor apenas em `127.0.0.1`, copia os endpoints reais para uma fixture e substitui WHMCS, banco e API por mocks. Não usa credenciais nem API Mercado Pago reais. Os 53 checks cobrem tokens ausente/incorreto/de outra sessão, expiração, propriedade da fatura, rejeições sem chamadas simuladas, PIX/boleto/cartão, duas abas e retry. O servidor é encerrado ao terminar.

Nesta rodada: PHPUnit 17 testes/48 assertions e PHPStan sem erros. A execução padrão `composer test` retornou aviso por ausência de driver de cobertura; `--no-coverage` passou. Cobertura, QA completo, matriz PHP 8.2/8.4 e WHMCS/browser real não foram executados localmente.
