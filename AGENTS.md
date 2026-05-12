# AGENTS.md — mercadopago-whmcs

Mercado Pago payment gateway module for WHMCS 8.x/9.x (Brazil). PIX, Boleto, Credit/Debit cards.

## Deploy (FTP)

Credentials in `.env` (gitignored). Source files map directly to WHMCS root:

```
modules/gateways/seixastec_mercadopago.php
modules/gateways/seixastec_mercadopago/     (Api.php, Validator.php, pay.php, process.php, logo.png, whmcs.json)
modules/gateways/callback/seixastec_mercadopago.php
includes/hooks/seixastec_mp_install.php
includes/hooks/seixastec_mp_cleanup.php
includes/hooks/seixastec_mercadopago_pdf.php
```

Use the PowerShell FTP script in `.env`-adjacent logic. Passive mode, binary, no keepalive.

## Architecture

- **`modules/gateways/seixastec_mercadopago.php`** — Gateway entrypoint: `_MetaData()`, `_config()`, `_link()` (Checkout Pro redirect), `_refund()`.
- **`Api.php`** — HTTP client for Mercado Pago API v1. Retry with exponential backoff (3x), deterministic idempotency keys (SHA-256, 1min window), strict SSL.
- **`Validator.php`** — CPF/CNPJ math validation. `final` class, all `static` methods. Sanitize → detect type → validate digits.
- **`pay.php`** — Custom checkout page using Payment Brick JS SDK v2. Bootstrap 5, Font Awesome.
- **`process.php`** — AJAX endpoint for Payment Brick submissions. Autoritative server-side amount recalculation (R$0.02 tolerance).
- **`callback/seixastec_mercadopago.php`** — Webhook/IPN handler. 7-layer security: HMAC-SHA256, anti-replay (5min), file lock, API re-verification, anti-duplication, anti-tampering, log masking.
- **`seixastec_mp_install.php`** — Auto-install hook. Creates `mod_seixastec_mp_transactions` table, runs migrations (v1=table, v2=indexes), auto-heal 1x/day.
- **`seixastec_mercadopago_pdf.php`** — Injects PIX QR Code + Boleto into invoice PDF (TCPDF), emails, and client area.
- **`seixastec_mp_cleanup.php`** — Daily cron: removes stale lock files (`mp_payment_*.lock` from sys temp dir).

## Critical conventions

- `declare(strict_types=1)` in every file. PHP 8.1+.
- Every file guarded with `if (!defined('WHMCS')) { die(...); }`.
- Manual autoload via `require_once` — no Composer. PSR-4 namespace: `WHMCS\Module\Gateway\SeixastecMercadoPago`.
- Database access via `Capsule` (Laravel Query Builder). Table: `mod_seixastec_mp_transactions`.
- All HTML output uses `htmlspecialchars(ENT_QUOTES)`. All external links use `target="_blank" rel="noopener"`.
- Lock files: `sys_get_temp_dir() . '/mp_payment_{id}.lock'`. Cleaned by daily cron hook.
- Config field access: always use `??` fallback (`$gateway['fieldName'] ?? ''`).
- Config field names used by each file must match `_config()` return keys exactly (see fix in v2.1.1).

## Two checkout paths

1. **Checkout Pro** (`_link()` in main gateway) — creates MP preference, redirects to `init_point`.
2. **Payment Brick** (`pay.php` → `process.php`) — custom page with JS SDK, needs Public Key.

Both use the same webhook callback for payment confirmation.

## No test suite

No PHPUnit, no CI/CD. Manual testing against a WHMCS installation. No build step — just copy files.

## Key quirks

- License is GPL-3.0. README, whmcs.json, and LICENSE must agree.
- The `seixastec_mp_install.php` hook manages schema version via `tblconfiguration` (`seixastec_mp_schema_version`). Current target: `SEIXASTEC_MP_SCHEMA_VERSION = 2`.
- Fee config uses `feePercent` (NOT `taxaPercentual`). Token config uses `accessTokenProd`/`accessTokenSandbox`.
