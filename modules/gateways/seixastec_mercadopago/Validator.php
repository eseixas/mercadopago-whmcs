<?php
/**
 * Mercado Pago - Validador de CPF e CNPJ
 *
 * Validação matemática completa dos documentos brasileiros:
 *   - CPF  (11 dígitos)  → algoritmo módulo 11 com pesos 10→2 e 11→2
 *   - CNPJ (14 dígitos)  → algoritmo módulo 11 com pesos 5→2,9→2 e 6→2,9→2
 *
 * Recursos:
 *   - Detecção automática do tipo (CPF/CNPJ) pelo comprimento
 *   - Sanitização: remove pontos, traços, barras e espaços
 *   - Rejeita sequências repetidas (111.111.111-11, 00.000.000/0000-00, etc.)
 *   - Formatação padrão brasileira para exibição
 *   - Mascaramento parcial para logs (LGPD)
 *
 * Compatível com: WHMCS 9.x | PHP 8.3
 *
 * Autor: Eduardo Seixas
 * Atualizado: 2026
 * Licença: GPL-3.0
 */

declare(strict_types=1);

namespace WHMCS\Module\Gateway\SeixastecMercadoPago;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

final class Validator
{
    /** Tipo de documento: CPF */
    public const TYPE_CPF = 'CPF';

    /** Tipo de documento: CNPJ */
    public const TYPE_CNPJ = 'CNPJ';

    /** Tipo indeterminado (comprimento inválido) */
    public const TYPE_INVALID = 'INVALID';

    // =======================================================================
    // API PÚBLICA
    // =======================================================================

    /**
     * Valida CPF ou CNPJ automaticamente pelo comprimento.
     *
     * @param string $document Documento com ou sem formatação
     * @return bool true se válido matematicamente
     */
    public static function validate(string $document): bool
    {
        $clean = self::sanitize($document);

        return match (strlen($clean)) {
            11      => self::validateCpf($clean),
            14      => self::validateCnpj($clean),
            default => false,
        };
    }

    /**
     * Valida CPF (11 dígitos).
     */
    public static function validateCpf(string $cpf): bool
    {
        $cpf = self::sanitize($cpf);

        if (strlen($cpf) !== 11) {
            return false;
        }

        if (self::isRepeatedSequence($cpf)) {
            return false;
        }

        // Primeiro dígito verificador (pesos 10 → 2)
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $cpf[$i] * (10 - $i);
        }
        $remainder = $sum % 11;
        $digit1    = ($remainder < 2) ? 0 : 11 - $remainder;

        if ((int) $cpf[9] !== $digit1) {
            return false;
        }

        // Segundo dígito verificador (pesos 11 → 2)
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += (int) $cpf[$i] * (11 - $i);
        }
        $remainder = $sum % 11;
        $digit2    = ($remainder < 2) ? 0 : 11 - $remainder;

        return (int) $cpf[10] === $digit2;
    }

    /**
     * Valida CNPJ (14 dígitos).
     */
    public static function validateCnpj(string $cnpj): bool
    {
        $cnpj = self::sanitize($cnpj);

        if (strlen($cnpj) !== 14) {
            return false;
        }

        if (self::isRepeatedSequence($cnpj)) {
            return false;
        }

        // Primeiro dígito verificador (pesos 5,4,3,2,9,8,7,6,5,4,3,2)
        $weights1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $cnpj[$i] * $weights1[$i];
        }
        $remainder = $sum % 11;
        $digit1    = ($remainder < 2) ? 0 : 11 - $remainder;

        if ((int) $cnpj[12] !== $digit1) {
            return false;
        }

        // Segundo dígito verificador (pesos 6,5,4,3,2,9,8,7,6,5,4,3,2)
        $weights2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $sum = 0;
        for ($i = 0; $i < 13; $i++) {
            $sum += (int) $cnpj[$i] * $weights2[$i];
        }
        $remainder = $sum % 11;
        $digit2    = ($remainder < 2) ? 0 : 11 - $remainder;

        return (int) $cnpj[13] === $digit2;
    }

    // =======================================================================
    // UTILITÁRIOS
    // =======================================================================

    /**
     * Remove todos os caracteres não-numéricos.
     */
    public static function sanitize(string $document): string
    {
        return preg_replace('/\D+/', '', $document) ?? '';
    }

    /**
     * Identifica o tipo de documento pelo comprimento (após sanitização).
     */
    public static function detectType(string $document): string
    {
        return match (strlen(self::sanitize($document))) {
            11      => self::TYPE_CPF,
            14      => self::TYPE_CNPJ,
            default => self::TYPE_INVALID,
        };
    }

    /**
     * Formata documento para exibição padrão brasileira.
     *
     *   CPF:  000.000.000-00
     *   CNPJ: 00.000.000/0000-00
     *
     * @return string Documento formatado, ou o original se inválido
     */
    public static function format(string $document): string
    {
        $clean = self::sanitize($document);

        return match (strlen($clean)) {
            11 => preg_replace(
                '/^(\d{3})(\d{3})(\d{3})(\d{2})$/',
                '$1.$2.$3-$4',
                $clean
            ) ?? $document,
            14 => preg_replace(
                '/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/',
                '$1.$2.$3/$4-$5',
                $clean
            ) ?? $document,
            default => $document,
        };
    }

    /**
     * Mascara documento para uso em logs (compliance LGPD).
     *
     *   CPF:  ***.***.***-12
     *   CNPJ: XX.XXX.XXX/XXXX-12
     */
    public static function mask(string $document): string
    {
        $clean = self::sanitize($document);
        $len   = strlen($clean);

        if ($len === 11) {
            return '***.***.***-' . substr($clean, -2);
        }

        if ($len === 14) {
            return 'XX.XXX.XXX/XXXX-' . substr($clean, -2);
        }

        return str_repeat('*', max(0, $len - 2)) . substr($clean, -2);
    }

    /**
     * Valida E retorna o documento sanitizado e formatado.
     *
     * @return array{valid:bool, type:string, clean:string, formatted:string, masked:string}
     */
    public static function inspect(string $document): array
    {
        $clean = self::sanitize($document);
        $type  = self::detectType($clean);
        $valid = match ($type) {
            self::TYPE_CPF  => self::validateCpf($clean),
            self::TYPE_CNPJ => self::validateCnpj($clean),
            default         => false,
        };

        return [
            'valid'     => $valid,
            'type'      => $type,
            'clean'     => $clean,
            'formatted' => self::format($clean),
            'masked'    => self::mask($clean),
        ];
    }

    /**
     * Verifica se a string é composta inteiramente por um único dígito repetido.
     * Ex.: "11111111111", "00000000000000"
     */
    private static function isRepeatedSequence(string $digits): bool
    {
        return strlen($digits) > 0
            && preg_match('/^(\d)\1+$/', $digits) === 1;
    }
}
