<?php
/**
 * Mercado Pago - Hook de Instalação / Auto-migração de Schema
 *
 * Cria automaticamente a tabela `mod_seixastec_mp_transactions` quando:
 *   1. O gateway é ativado (hook AfterModuleActivate)
 *   2. Detecta schema defasado em qualquer página admin (auto-heal, 1x/dia)
 *
 * Sistema de versionamento incremental:
 *   - Versão atual definida em SEIXASTEC_MP_SCHEMA_VERSION
 *   - Versão instalada armazenada em tblconfiguration
 *   - Migrações executadas em ordem, idempotentes
 *
 * Tabela criada:
 *   mod_seixastec_mp_transactions
 *     ├─ id                INT AUTO_INCREMENT PRIMARY KEY
 *     ├─ invoice_id        INT          UNIQUE
 *     ├─ preference_id     VARCHAR(100) NULL
 *     ├─ payment_id        VARCHAR(100) NULL  INDEX
 *     ├─ method            VARCHAR(30)  NULL
 *     ├─ status            VARCHAR(30)  DEFAULT 'pending'  INDEX
 *     ├─ amount            DECIMAL(12,2) DEFAULT 0
 *     ├─ amount_refunded   DECIMAL(12,2) DEFAULT 0
 *     ├─ pix_qr_base64     MEDIUMTEXT   NULL
 *     ├─ pix_copia_cola    TEXT         NULL
 *     ├─ boleto_url        TEXT         NULL
 *     ├─ boleto_linha      VARCHAR(100) NULL
 *     ├─ paid_at           TIMESTAMP    NULL
 *     ├─ created_at        TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
 *     └─ updated_at        TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
 *
 * Compatível com: WHMCS 8.x / 9.x | PHP 8.1+ | MySQL 5.7+ / MariaDB 10.3+
 *
 * Autor: Eduardo Seixas
 * Versão: 1.0.0
 * Atualizado: 2026-05-11
 * Licença: GPL-3.0
 */

declare(strict_types=1);

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Versão atual do schema — incrementar a cada nova migration. */
const SEIXASTEC_MP_SCHEMA_VERSION = 2;

/** Nome interno do módulo. */
const SEIXASTEC_MP_MODULE = 'seixastec_mercadopago';

/** Nome da tabela principal. */
const SEIXASTEC_MP_TABLE = 'mod_seixastec_mp_transactions';

/** Chave de configuração para versão instalada. */
const SEIXASTEC_MP_VERSION_KEY = 'seixastec_mp_schema_version';

/** Chave de configuração para timestamp da última verificação. */
const SEIXASTEC_MP_LASTCHECK_KEY = 'seixastec_mp_schema_lastcheck';

// =======================================================================
// HOOK 1: AfterModuleActivate (ativação do gateway)
// =======================================================================

add_hook('AfterModuleActivate', 1, function (array $vars): void {
    if (($vars['module'] ?? '') !== SEIXASTEC_MP_MODULE) {
        return;
    }

    try {
        seixastec_mp_runMigrations(true);
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) {
            logActivity('[Mercado Pago] Falha na ativação: ' . $e->getMessage());
        }
    }
});

// =======================================================================
// HOOK 2: AdminAreaPage (auto-heal preguiçoso, 1x por dia)
// =======================================================================

add_hook('AdminAreaPage', 1, function (array $vars): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $lastCheck = (int) (Capsule::table('tblconfiguration')
            ->where('setting', SEIXASTEC_MP_LASTCHECK_KEY)
            ->value('value') ?: 0);

        // Verifica no máximo 1x por dia
        if ((time() - $lastCheck) < 86400) {
            return;
        }

        if (seixastec_mp_needsMigration()) {
            seixastec_mp_runMigrations(false);
        }

        // Registra timestamp da verificação
        Capsule::table('tblconfiguration')->updateOrInsert(
            ['setting' => SEIXASTEC_MP_LASTCHECK_KEY],
            ['value' => (string) time()]
        );
    } catch (\Throwable $e) {
        // silencioso — não bloqueia o admin
    }
});

// =======================================================================
// CONTROLE DE VERSÃO
// =======================================================================

/**
 * Retorna a versão de schema atualmente instalada.
 */
function seixastec_mp_getInstalledVersion(): int
{
    try {
        $value = Capsule::table('tblconfiguration')
            ->where('setting', SEIXASTEC_MP_VERSION_KEY)
            ->value('value');
        return (int) ($value ?? 0);
    } catch (\Throwable $e) {
        return 0;
    }
}

/**
 * Persiste a versão de schema instalada.
 */
function seixastec_mp_setInstalledVersion(int $version): void
{
    try {
        Capsule::table('tblconfiguration')->updateOrInsert(
            ['setting' => SEIXASTEC_MP_VERSION_KEY],
            ['value' => (string) $version]
        );
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) {
            logActivity('[Mercado Pago] Falha salvar versão: ' . $e->getMessage());
        }
    }
}

/**
 * Verifica se há migrações pendentes.
 */
function seixastec_mp_needsMigration(): bool
{
    return seixastec_mp_getInstalledVersion() < SEIXASTEC_MP_SCHEMA_VERSION;
}

// =======================================================================
// EXECUTOR DE MIGRAÇÕES
// =======================================================================

/**
 * Executa todas as migrações pendentes em ordem.
 *
 * @param bool $verbose Se true, registra no logActivity (uso na ativação).
 */
function seixastec_mp_runMigrations(bool $verbose = false): void
{
    $current = seixastec_mp_getInstalledVersion();

    if ($current >= SEIXASTEC_MP_SCHEMA_VERSION) {
        return;
    }

    $migrations = [
        1 => 'seixastec_mp_migration_v1_createTable',
        2 => 'seixastec_mp_migration_v2_addIndexes',
        // 3 => 'seixastec_mp_migration_v3_...' (adicione futuras aqui)
    ];

    foreach ($migrations as $version => $callback) {
        if ($version <= $current) {
            continue;
        }

        try {
            $callback();
            seixastec_mp_setInstalledVersion($version);

            if ($verbose && function_exists('logActivity')) {
                logActivity("[Mercado Pago] Migration v{$version} aplicada com sucesso.");
            }
        } catch (\Throwable $e) {
            if (function_exists('logActivity')) {
                logActivity("[Mercado Pago] FALHA migration v{$version}: " . $e->getMessage());
            }
            break; // interrompe próximas migrações
        }
    }
}

// =======================================================================
// MIGRAÇÕES
// =======================================================================

/**
 * Migration v1 — Cria tabela principal.
 */
function seixastec_mp_migration_v1_createTable(): void
{
    $schema = Capsule::schema();

    if ($schema->hasTable(SEIXASTEC_MP_TABLE)) {
        return; // idempotente
    }

    $schema->create(SEIXASTEC_MP_TABLE, function ($table) {
        $table->bigIncrements('id');

        // Vínculos
        $table->unsignedInteger('invoice_id')->unique()
            ->comment('ID da fatura no WHMCS (tblinvoices.id)');
        $table->string('preference_id', 100)->nullable()
            ->comment('ID da preferência no Mercado Pago');
        $table->string('payment_id', 100)->nullable()
            ->comment('ID do pagamento no Mercado Pago');

        // Pagamento
        $table->string('method', 30)->nullable()
            ->comment('pix|bolbradesco|credit_card|debit_card|...');
        $table->string('status', 30)->default('pending')
            ->comment('pending|approved|rejected|refunded|...');
        $table->decimal('amount', 12, 2)->default(0)
            ->comment('Valor total da transação (BRL)');
        $table->decimal('amount_refunded', 12, 2)->default(0)
            ->comment('Valor reembolsado');

        // PIX
        $table->mediumText('pix_qr_base64')->nullable()
            ->comment('QR Code do PIX em base64 (PNG)');
        $table->text('pix_copia_cola')->nullable()
            ->comment('Código PIX Copia e Cola');

        // Boleto
        $table->text('boleto_url')->nullable()
            ->comment('URL do boleto no MP');
        $table->string('boleto_linha', 100)->nullable()
            ->comment('Linha digitável do boleto');

        // Timestamps
        $table->timestamp('paid_at')->nullable()
            ->comment('Data de aprovação do pagamento');
        $table->timestamp('created_at')->useCurrent();
        $table->timestamp('updated_at')->useCurrent();

        // Engine + charset
        $table->engine    = 'InnoDB';
        $table->charset   = 'utf8mb4';
        $table->collation = 'utf8mb4_unicode_ci';
    });
}

/**
 * Migration v2 — Adiciona índices auxiliares para performance.
 */
function seixastec_mp_migration_v2_addIndexes(): void
{
    $schema = Capsule::schema();

    if (!$schema->hasTable(SEIXASTEC_MP_TABLE)) {
        return;
    }

    $existing = seixastec_mp_getExistingIndexes(SEIXASTEC_MP_TABLE);

    $schema->table(SEIXASTEC_MP_TABLE, function ($table) use ($existing) {
        if (!in_array('idx_mp_payment_id', $existing, true)) {
            $table->index('payment_id', 'idx_mp_payment_id');
        }
        if (!in_array('idx_mp_preference_id', $existing, true)) {
            $table->index('preference_id', 'idx_mp_preference_id');
        }
        if (!in_array('idx_mp_status', $existing, true)) {
            $table->index('status', 'idx_mp_status');
        }
        if (!in_array('idx_mp_created_at', $existing, true)) {
            $table->index('created_at', 'idx_mp_created_at');
        }
    });
}

// =======================================================================
// UTILITÁRIOS
// =======================================================================

/**
 * Lista os índices existentes na tabela.
 *
 * @return string[]
 */
function seixastec_mp_getExistingIndexes(string $tableName): array
{
    try {
        $rows = Capsule::select("SHOW INDEX FROM `{$tableName}`");
        $names = [];
        foreach ($rows as $row) {
            $names[] = (string) ($row->Key_name ?? '');
        }
        return array_values(array_unique(array_filter($names)));
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Diagnóstico público — pode ser chamado via cron ou admin tool.
 *
 * @return array Status atual da instalação
 */
function seixastec_mp_getInstallStatus(): array
{
    return [
        'module'           => SEIXASTEC_MP_MODULE,
        'table'            => SEIXASTEC_MP_TABLE,
        'table_exists'     => Capsule::schema()->hasTable(SEIXASTEC_MP_TABLE),
        'schema_target'    => SEIXASTEC_MP_SCHEMA_VERSION,
        'schema_installed' => seixastec_mp_getInstalledVersion(),
        'needs_migration'  => seixastec_mp_needsMigration(),
        'indexes'          => seixastec_mp_getExistingIndexes(SEIXASTEC_MP_TABLE),
    ];
}
