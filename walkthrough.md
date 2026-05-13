# Walkthrough: Arquitetura PSR-4 e Testes Unitários

O plano de implementação focado em alinhar o `composer.json` à estrutura real do WHMCS e garantir a estabilidade do módulo através de testes foi finalizado com sucesso.

## Alterações Realizadas

### 1. Correção do Autoloader (`composer.json`)
A declaração anterior (`"Eseixas\\MercadoPagoWhmcs\\": "src/"`) foi identificada como uma anomalia herdada que não refletia a estrutura exigida pelo módulo no ambiente do WHMCS.
- Atualizamos para: `"WHMCS\\Module\\Gateway\\SeixastecMercadoPago\\": "modules/gateways/seixastec_mercadopago/"`.
- O ambiente de desenvolvimento (`autoload-dev`) foi mapeado corretamente para o diretório `tests/`.

### 2. Implementação de Testes Unitários
Como o módulo lida com dados transacionais e sanitização de identificadores locais (CPFs e CNPJs), introduzimos a suíte de testes de núcleo:
- **`ValidatorTest.php`**: Cobre a detecção de tipo (`CPF`/`CNPJ`), as rotinas de sanitização anti-injecao e a mecânica de segurança de mascaramento de documentos.
- **`ApiTest.php`**: Verifica a prevenção de instâncias vazias da API, garantindo que exceções corretas sejam lançadas se não houver Token, além de testar a precisão da detecção entre tokens de Sandbox (`TEST-`, `APP_USR-TEST-`) versus Produção.

## Próximos Passos (Para o Usuário)

> [!IMPORTANT]
> **Execução dos Testes**
> Como o ambiente local onde estou operando não possui os binários instalados (o diretório `vendor` ainda não existe), você precisará rodar os testes a partir do seu console principal.

Siga estas instruções no seu terminal:
1. Gere o autoloader corrigido e instale as dependências:
   ```bash
   composer install
   ```
2. Execute a suíte de testes:
   ```bash
   vendor/bin/phpunit
   ```

Se todos os testes passarem (verde), você pode prosseguir com o empacotamento da release ou com a subida dos arquivos para o FTP do seu WHMCS de produção em segurança.
