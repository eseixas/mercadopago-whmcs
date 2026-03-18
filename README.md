<div align="center">

# 🛒 Mercado Pago — Gateway de Pagamento para WHMCS

[![WHMCS](https://img.shields.io/badge/WHMCS-9.x-blue?style=flat-square)](https://www.whmcs.com)
[![PHP](https://img.shields.io/badge/PHP-8.3-777BB4?style=flat-square&logo=php)](https://www.php.net)
[![API](https://img.shields.io/badge/Mercado%20Pago-API%20v1-009ee3?style=flat-square)](https://www.mercadopago.com.br/developers)
[![License: GPL-3.0](https://img.shields.io/badge/License-GPL--3.0-green?style=flat-square)](LICENSE)
[![Version](https://img.shields.io/badge/Version-1.0.0-orange?style=flat-square)](CHANGELOG.md)

Integração completa com o **Mercado Pago** para WHMCS 9.x via API — com suporte a PIX, Boleto, Cartão de Crédito e Débito, confirmação automática de pagamentos e muito mais.

</div>

---

## ✨ Funcionalidades

| Recurso | Disponível |
|---|:---:|
| 📱 PIX — QR Code + Copia e Cola | ✅ |
| 🏦 Boleto Bancário — Linha Digitável | ✅ |
| 💳 Cartão de Crédito | ✅ |
| 💳 Cartão de Débito | ✅ |
| 🔔 Confirmação automática via Webhook | ✅ |
| 🔄 Polling JS automático na fatura pós-PIX | ✅ |
| 💸 Reembolso total e parcial pelo WHMCS | ✅ |
| 📄 PIX e Boleto no **PDF** da fatura | ✅ |
| 📧 PIX e Boleto no **e-mail** da fatura | ✅ |
| 🔗 External Reference = ID da Fatura WHMCS | ✅ |
| 🔍 Log de portal aprimorado | ✅ |
| 💰 Taxa fixa e percentual configuráveis | ✅ |
| ⚖️ Multa por atraso (máx. 2% — CDC) | ✅ |
| 📈 Juros proporcional por dia | ✅ |
| 🪪 Validação de CPF e CNPJ | ✅ |
| 🧩 Dropdown dinâmico para campo CPF/CNPJ | ✅ |
| 🧪 Modo Sandbox | ✅ |

---

## 📁 Estrutura de Arquivos

```
whmcs-mercadopago/
├── modules/
│   └── gateways/
│       ├── mercadopago.php              ← Módulo principal (config, link, refund)
│       ├── mercadopago/
│       │   ├── Api.php                  ← Cliente HTTP para a API do Mercado Pago
│       │   └── Validator.php            ← Validador de CPF e CNPJ
│       └── callback/
│           └── mercadopago.php          ← Webhook / IPN Handler
├── includes/
│   └── hooks/
│       └── mercadopago_pdf.php          ← Hook para PDF e e-mail da fatura
├── .gitignore
├── CHANGELOG.md
├── LICENSE
└── README.md
```

---

## ⚙️ Requisitos

- **WHMCS** 9.0 ou superior
- **PHP** 8.2 ou superior
- Extensão **cURL** habilitada no servidor
- Conta ativa no **Mercado Pago Brasil**
- URL pública (HTTPS) para recebimento de Webhooks

---

## 🚀 Instalação

### 1. Copiar os arquivos

Copie as pastas `modules/` e `includes/` para a **raiz do seu WHMCS**, mantendo a estrutura de diretórios exatamente como está neste repositório.

### 2. Ativar o gateway

No painel admin do WHMCS:  
**Configurações → Gateways de Pagamento → Todos os Gateways → Mercado Pago → Ativar**

### 3. Configurar o gateway

Acesse **Configurações → Gateways de Pagamento → Configurar → Mercado Pago** e preencha:

| Campo | Descrição |
|---|---|
| **Access Token (Produção)** | Obtido em [Suas Credenciais](https://www.mercadopago.com.br/settings/account/credentials) no painel do MP |
| **Access Token (Sandbox)** | Token do ambiente de testes |
| **Modo Sandbox** | Ative para testar sem cobranças reais |
| **Taxa Percentual (%)** | Ex.: `2.5` — cobrada além do valor da fatura |
| **Taxa Fixa (R$)** | Ex.: `2.00` — valor fixo adicional |
| **Vencimento padrão (dias)** | Dias para vencimento de boletos reemitidos |
| **Multa por atraso (%)** | Máximo permitido: `2` (CDC Art. 52 §1º) |
| **Juros proporcional (% ao mês)** | Ex.: `1` (= 0,033%/dia) |
| **Gerar para todos os pedidos?** | Sim = qualquer fatura; Não = somente quando cliente escolhe o gateway |
| **Campo CPF/CNPJ** | Selecione da lista suspensa (campos do cliente carregados automaticamente) |
| **Validar CPF/CNPJ no checkout?** | Valida matematicamente antes de redirecionar |

### 4. Configurar o Webhook

No painel do Mercado Pago:  
**Seu negócio → Configurações → Notificações (Webhooks)**

Adicione a URL abaixo e marque os eventos **Payments** e **Merchant orders**:

```
https://SEU_WHMCS_URL/modules/gateways/callback/mercadopago.php
```

---

## 💳 Fluxo de Pagamento

```
Cliente acessa a fatura no WHMCS
        ↓
Clica em "Pagar agora" → aba do Mercado Pago (Checkout Pro) abre
        ↓
Cliente escolhe PIX / Boleto / Cartão e efetua o pagamento
        ↓
Mercado Pago envia Webhook → callback/mercadopago.php
        ↓
Módulo verifica o status "approved" via API (não confia apenas no payload)
        ↓
AddInvoicePayment() → Fatura baixada automaticamente no WHMCS ✅
        ↓
JS da fatura detecta o status "Pago" → exibe banner e redireciona o cliente
```

---

## 💸 Reembolso

1. Abra a fatura paga no painel admin do WHMCS.
2. Clique em **Reembolsar** e informe o valor desejado (total ou parcial).
3. O módulo chama automaticamente `POST /v1/payments/{id}/refunds` no Mercado Pago.

---

## 📄 PIX e Boleto no PDF / E-mail

O hook `mercadopago_pdf.php` consulta a API do MP após a criação da preferência e recupera:

- **PIX:** QR Code em base64 + Código Copia e Cola
- **Boleto:** Linha digitável + link para impressão

Essas informações são injetadas nas variáveis do PDF (`InvoicePdfVars`) e no corpo HTML dos e-mails (`EmailPreSend`).

> **Observação:** O QR Code e a linha digitável ficam disponíveis no MP **após** o cliente iniciar o fluxo de pagamento no Checkout Pro.

---

## 🔐 Segurança

- O Access Token é **mascarado** em todos os registros de log do WHMCS.
- A comunicação usa exclusivamente **HTTPS** com verificação SSL ativada.
- O webhook **verifica o pagamento diretamente na API** do MP antes de registrar — não confia apenas no payload recebido.
- As transações são idempotentes: o WHMCS ignora pagamentos já registrados.

---

## 📝 Licença

Este projeto está licenciado sob a [MIT License](LICENSE).

---

<div align="center">

Feito com ❤️ por **Eduardo Seixas**  
Contribuições são bem-vindas via Pull Request!

</div>
