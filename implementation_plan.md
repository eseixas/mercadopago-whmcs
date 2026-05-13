# Plano de Implementação: Arquitetura PSR-4 e Testes Unitários

Este documento detalha o plano para executar as recomendações arquiteturais validadas, focando em alinhar o `composer.json` à estrutura real do projeto e introduzir testes automatizados para as lógicas críticas.

## User Review Required

Nenhuma alteração disruptiva em produção (os arquivos finais continuarão compatíveis com FTP), mas a forma de rodar os testes e a configuração do Composer será padronizada. Por favor, aprove este plano para iniciarmos.

## Proposed Changes

### Dependências e Configuração

#### [MODIFY] [composer.json](file:///c:/Temp/code/mercadopago-whmcs/composer.json)
- Atualizar a seção `autoload.psr-4` para mapear `WHMCS\\Module\\Gateway\\SeixastecMercadoPago\\` para a pasta `modules/gateways/seixastec_mercadopago/`.
- Remover a referência fantasma à pasta `src/`.
- Atualizar a seção `autoload-dev.psr-4` para mapear o namespace de testes `WHMCS\\Module\\Gateway\\SeixastecMercadoPago\\Tests\\` para a pasta `tests/`.

### Testes Unitários

#### [NEW] [tests/ValidatorTest.php](file:///c:/Temp/code/mercadopago-whmcs/tests/ValidatorTest.php)
Criar suíte de testes cobrindo a classe `Validator`:
- Testes de limpeza/sanitização de strings.
- Testes de identificação correta entre CPF e CNPJ.
- Testes de validação matemática do dígito verificador (casos de sucesso e falha para CPFs/CNPJs conhecidos).
- Testes do mascaramento (`maskDocument()`).

#### [NEW] [tests/ApiTest.php](file:///c:/Temp/code/mercadopago-whmcs/tests/ApiTest.php)
Criar suíte de testes cobrindo a classe `Api`:
- Instanciação de classe (rejeição de tokens vazios).
- Testes da detecção de Sandbox vs Produção com base nos prefixos (`TEST-`, `APP_USR-TEST-`).
- Mocks para testar o parseamento de mensagens de erro estruturadas da API do Mercado Pago.
- (Opcional, se o ambiente permitir) Testes de geração correta da chave de idempotência.

## Verification Plan

### Automated Tests
- Rodar `composer dump-autoload` para aplicar os novos mappings.
- Executar `vendor/bin/phpunit` para garantir que as suítes de teste de CPF/CNPJ e API passem localmente.

### Manual Verification
- Nenhuma funcionalidade de gateway em si será alterada, a verificação ocorrerá primariamente via green build do PHPUnit.
