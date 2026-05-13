# AGENTS.md — mercadopago-whmcs

Mercado Pago payment gateway module for WHMCS 8.10+/9.x (Brazil). PIX, Boleto, Credit/Debit cards.

## Source layout (files map 1:1 to WHMCS root on deploy)

```
modules/gateways/seixastec_mercadopago.php  — WHMCS gateway entrypoint (config, link, refund)
modules/gateways/seixastec_mercadopago/
  Api.php               — HTTP client for MP API v1, retry 3x exp backoff, idempotency keys
  Validator.php          — final class, static CPF/CNPJ math validation
  TemplateRenderer.php   — Smarty renderer with theme-override support
  pay.php               — Custom checkout page (Payment Brick JS SDK v2)
  process.php           — AJAX endpoint for Brick submissions, server-side amount recalculation
  templates/            — 7 Smarty templates (pix, boleto, checkout_pro, choice, alert, existing_approved, assets)
  logo.png, whmcs.json
modules/gateways/callback/seixastec_mercadopago.php  — Webhook/IPN handler, HMAC-SHA256
includes/hooks/
  seixastec_mp_install.php        — Auto-install + schema migration (current: v2)
  seixastec_mp_cleanup.php        — Daily cron: stale lock file removal
  seixastec_mercadopago.php       — 8-hook system (DailyCronJob, InvoicePaid/Cancelled/Creation, admin UI, client sidebar)
  seixastec_mercadopago_pdf.php   — PIX QR + Boleto injection in TCPDF PDF, emails (merge_fields), client area
```

## Dev commands

```bash
composer qa              # cs:check → phpstan → test (full gate)
composer qa:fix          # cs:fix + rector:fix (auto-fix)
composer test            # phpunit all
composer test:unit       # Unit suite only
composer test:integration # Integration suite only
composer cs:fix          # auto-format PHP-CS-Fixer
composer phpstan         # static analysis (256M memory)
composer build           # production build: composer install --no-dev + scripts/build-release.php
```

CI (GitHub Actions): lint → phpstan → test matrix (PHP 8.2/8.3/8.4 × prefer-lowest/prefer-stable) → security audit → editorconfig → release on v* tags.

## Key conventions

- `declare(strict_types=1)` in every file. PHP 8.2+ required (composer.json).
- Every file guarded with `if (!defined('WHMCS')) { die(...); }`.
- Manual `require_once` autoload (WHMCS doesn't run Composer's autoload at module runtime). PSR-4 namespace: `WHMCS\Module\Gateway\SeixastecMercadoPago`.
- DB via Capsule (Laravel Query Builder). Table: `mod_seixastec_mp_transactions`.
- HTML output: `htmlspecialchars($var, ENT_QUOTES)`. External links: `target="_blank" rel="noopener"`.
- Config access: `$gateway['fieldName'] ?? ''` (never bare array access).
- Lock files: `sys_get_temp_dir() . '/mp_payment_*.lock'`, cleaned by daily cron.
- Test bootstrap defines `WHMCS` constant + stubs `logModuleCall()` — tests need `vendor/autoload.php`.
- Test stubs in `tests/stubs/whmcs.stub`.

## Two checkout paths (same callback)

1. **Checkout Pro** (`_link()` in main gateway) — creates MP preference, redirects to `init_point`.
2. **Payment Brick** (`pay.php` → `process.php`) — custom JS SDK page, needs Public Key.

## Common pitfalls

- README.md is a template/generic doc — **trust the code, not README**. Actual file structure uses `seixastec_mercadopago` prefix, not `mercadopago`. README license badge says MIT but the actual license is **GPL-3.0** (composer.json, LICENSE, whmcs.json agree). Schema field `feePercent` (not `taxaPercentual`).
- Config fields consolidated in v2.2.0+: use **`accessToken`** and **`publicKey`** (no `accessTokenProd`/`accessTokenSandbox` split).
- `.env` is gitignored — contains FTP credentials for deploy script. `_mp_diag.php` is gitignored (diagnostic tool). Never commit these.
- Deploy via `deploy_ftp.ps1` which uses WSL+lftp, not a native PowerShell FTP client. Reads `FTP_HOST/USER/PASS/REMOTE_BASE` from `.env`.
