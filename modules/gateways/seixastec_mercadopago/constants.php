<?php

declare(strict_types=1);

/**
 * Constantes compartilhadas do módulo (define() para evitar redeclaração fatal
 * quando vários arquivos em includes/hooks/ são carregados no mesmo request).
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

if (!defined('SEIXASTEC_MP_MODULE')) {
    define('SEIXASTEC_MP_MODULE', 'seixastec_mercadopago');
}

if (!defined('SEIXASTEC_MP_TABLE')) {
    define('SEIXASTEC_MP_TABLE', 'mod_seixastec_mp_transactions');
}

if (!defined('SEIXASTEC_MP_HOOK_PRIORITY')) {
    define('SEIXASTEC_MP_HOOK_PRIORITY', 50);
}

if (!defined('SEIXASTEC_MP_SCHEMA_VERSION')) {
    define('SEIXASTEC_MP_SCHEMA_VERSION', 3);
}

if (!defined('SEIXASTEC_MP_VERSION_KEY')) {
    define('SEIXASTEC_MP_VERSION_KEY', 'seixastec_mp_schema_version');
}

if (!defined('SEIXASTEC_MP_LASTCHECK_KEY')) {
    define('SEIXASTEC_MP_LASTCHECK_KEY', 'seixastec_mp_schema_lastcheck');
}

if (!defined('SEIXASTEC_MP_HOOK_VERSION')) {
    define('SEIXASTEC_MP_HOOK_VERSION', '1.2.0');
}

if (!defined('SEIXASTEC_MP_GATEWAY_MODULE')) {
    define('SEIXASTEC_MP_GATEWAY_MODULE', SEIXASTEC_MP_MODULE);
}
