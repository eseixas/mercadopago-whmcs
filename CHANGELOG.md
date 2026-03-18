# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
