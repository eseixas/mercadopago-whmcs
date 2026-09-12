<?php

declare(strict_types=1);

/**
 * Empacota os arquivos do módulo para distribuição (espelho da raiz WHMCS).
 */

$root = dirname(__DIR__);
$dest = $root . '/build/release/seixastec_mercadopago';

$paths = [
    'modules/gateways/seixastec_mercadopago.php',
    'modules/gateways/callback/seixastec_mercadopago.php',
    'modules/gateways/seixastec_mercadopago',
    'includes/hooks/seixastec_mercadopago.php',
    'includes/hooks/seixastec_mercadopago_pdf.php',
    'includes/hooks/seixastec_mp_cleanup.php',
    'includes/hooks/seixastec_mp_install.php',
];

$iterator = static function (string $from, string $to) use (&$iterator): void {
    if (is_dir($from)) {
        if (!is_dir($to) && !mkdir($to, 0755, true) && !is_dir($to)) {
            throw new RuntimeException('Não foi possível criar ' . $to);
        }
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $iterator($from . '/' . $entry, $to . '/' . $entry);
        }
        return;
    }

    $dir = dirname($to);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Não foi possível criar ' . $dir);
    }
    if (!copy($from, $to)) {
        throw new RuntimeException('Falha ao copiar ' . $from);
    }
};

if (is_dir($dest)) {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dest, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
}

foreach ($paths as $relative) {
    $from = $root . '/' . $relative;
    if (!file_exists($from)) {
        fwrite(STDERR, "Aviso: {$relative} não encontrado\n");
        continue;
    }
    $iterator($from, $dest . '/' . $relative);
}

echo "Release gerado em {$dest}\n";
