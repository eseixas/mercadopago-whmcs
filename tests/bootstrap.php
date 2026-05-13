<?php

declare(strict_types=1);

/**
 * Bootstrap dos testes - Mercado Pago WHMCS
 */

// Autoload do Composer
require_once __DIR__ . '/../vendor/autoload.php';

// Definir constantes que o WHMCS normalmente define
if (!defined('WHMCS')) {
    define('WHMCS', true);
}

// Timezone padrão
date_default_timezone_set('America/Sao_Paulo');

// Reporting de erros máximo em testes
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Stubs/mocks globais do WHMCS (se necessário)
if (!function_exists('logModuleCall')) {
    /**
     * Stub da função global logModuleCall do WHMCS.
     */
    function logModuleCall(
        string $module,
        string $action,
        mixed $requestString,
        mixed $responseData,
        mixed $processedData = '',
        array $replaceVars = []
    ): void {
        // No-op em testes
    }
}
