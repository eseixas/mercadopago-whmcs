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

## Finalização e Deploy

O processo de modernização e implantação foi concluído com as seguintes etapas:

1.  **GitHub Atualizado**: Todas as alterações (correções de autoloader, cleanup de código e novos testes) foram enviadas para o repositório principal.
2.  **Deploy via FTP**: Os arquivos foram sincronizados com o servidor de produção (`portugal.nitmail.com`) utilizando o script de automação `deploy_ftp.ps1`, garantindo que o WHMCS agora utilize a lógica unificada de credenciais e a arquitetura de templates corrigida.

### Resumo do Deploy
- **Gateway Principal**: Sincronizado.
- **Diretório de Lógica/Templates**: Espelhado via `mirror`.
- **Hooks de Automação**: Atualizados (Install, Cleanup, PDF).
- **Callback**: Sincronizado para garantir confirmações de pagamento seguras.

O módulo está agora operacional na versão 2.3.0. Para futuras atualizações, o script `deploy_ftp.ps1` pode ser reutilizado para sincronização rápida.

