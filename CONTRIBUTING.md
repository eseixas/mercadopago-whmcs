# 🤝 Guia de Contribuição

Obrigado pelo interesse em contribuir com o **Mercado Pago Gateway for WHMCS**! 🎉

Este documento descreve como você pode contribuir de forma efetiva. Leia até o final antes de abrir sua primeira PR.

---

## 📑 Índice

- [Código de Conduta](#-código-de-conduta)
- [Como Contribuir](#-como-contribuir)
- [Reportando Bugs](#-reportando-bugs)
- [Sugerindo Features](#-sugerindo-features)
- [Setup de Desenvolvimento](#-setup-de-desenvolvimento)
- [Padrões de Código](#-padrões-de-código)
- [Convenção de Commits](#-convenção-de-commits)
- [Processo de Pull Request](#-processo-de-pull-request)
- [Testes](#-testes)
- [Documentação](#-documentação)
- [Releases](#-releases)

---

## 📜 Código de Conduta

Este projeto adota o [**Code of Conduct**](CODE_OF_CONDUCT.md). Ao participar, você concorda em manter um ambiente respeitoso e acolhedor.

---

## 🚀 Como Contribuir

Existem várias formas de contribuir:

| Tipo | Como ajudar |
|------|-------------|
| 🐛 **Bugs** | Reportar problemas detalhadamente |
| 💡 **Features** | Sugerir e implementar novas funcionalidades |
| 📖 **Documentação** | Melhorar README, docs/, comentários no código |
| 🧪 **Testes** | Aumentar cobertura de testes |
| 🌐 **Traduções** | Adicionar/melhorar idiomas em `lang/` |
| 🎨 **UX/UI** | Melhorar templates do checkout |
| 💬 **Suporte** | Responder issues de outros usuários |

---

## 🐛 Reportando Bugs

Antes de abrir uma issue:

1. ✅ **Verifique** se já não existe uma issue similar nos [issues abertos e fechados](https://github.com/eseixas/mercadopago-whmcs/issues?q=is%3Aissue)
2. ✅ **Atualize** para a versão mais recente e tente reproduzir
3. ✅ **Reúna informações** antes de reportar

### Use o template de bug report

Use o [**template de bug report**](https://github.com/eseixas/mercadopago-whmcs/issues/new?template=bug_report.md) e inclua **OBRIGATORIAMENTE**:

- ✅ Versão do **WHMCS** (ex: `8.13.0`)
- ✅ Versão do **PHP** (ex: `8.3.2`)
- ✅ Versão do **módulo** (ex: `v2.1.0`)
- ✅ **Passos para reproduzir** (numerados)
- ✅ **Comportamento esperado** vs **comportamento atual**
- ✅ **Logs completos** do Gateway Log (mascare dados sensíveis!)
- ✅ **Prints** se ajudar a entender o problema

### ⚠️ NÃO inclua nas issues

- ❌ Access Tokens, Public Keys ou Webhook Secrets
- ❌ Dados pessoais de clientes (CPF, email, telefone)
- ❌ IDs de transação reais sem mascarar
- ❌ Credenciais de banco de dados ou SSH

---

## 💡 Sugerindo Features

Use o [**template de feature request**](https://github.com/eseixas/mercadopago-whmcs/issues/new?template=feature_request.md) e descreva:

1. **Qual problema** essa feature resolve?
2. **Solução proposta** (seja específico)
3. **Alternativas consideradas**
4. **Impacto** em quem (clientes, admins, devs?)
5. **Mockups/exemplos** se aplicável

> 💡 Features grandes devem ser discutidas em issue **antes** de abrir PR.

---

## 🛠️ Setup de Desenvolvimento

### Pré-requisitos

- PHP 8.1+
- Composer 2.x
- Git
- Docker + Docker Compose (opcional, mas recomendado)
- WHMCS local para testes (licença Dev gratuita)

### Passo a passo

```bash
# 1. Fork o repositório no GitHub e clone seu fork
git clone https://github.com/SEU-USUARIO/mercadopago-whmcs.git
cd mercadopago-whmcs

# 2. Adicione o upstream
git remote add upstream https://github.com/eseixas/mercadopago-whmcs.git

# 3. Instale dependências
composer install

# 4. Copie configurações de ambiente
cp .env.example .env

# 5. (Opcional) Suba ambiente Docker
docker-compose up -d

# 6. Rode os testes para garantir que tudo funciona
composer test
