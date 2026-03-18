<?php

/**
 * Mercado Pago - WHMCS Gateway Module
 * CPF / CNPJ Validator
 *
 * @author      Eduardo Seixas
 * @copyright   2026
 * @license     GPL-3.0
 */

declare(strict_types=1);

namespace WHMCS\Module\Gateway\MercadoPago;

class Validator
{
    /**
     * Validate either a CPF (11 digits) or CNPJ (14 digits).
     *
     * @param  string $value  Raw value (may contain dots, dashes, slashes).
     * @return bool
     */
    public static function validateCpfCnpj(string $value): bool
    {
        $digits = preg_replace('/\D/', '', $value);

        if (strlen($digits) === 11) {
            return self::validateCpf($digits);
        }

        if (strlen($digits) === 14) {
            return self::validateCnpj($digits);
        }

        return false;
    }

    /**
     * Determine whether a sanitised value is a CPF or a CNPJ.
     *
     * @return string  'CPF' | 'CNPJ' | 'unknown'
     */
    public static function detectType(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value);

        return match (strlen($digits)) {
            11      => 'CPF',
            14      => 'CNPJ',
            default => 'unknown',
        };
    }

    // -----------------------------------------------------------------------
    // Private validators
    // -----------------------------------------------------------------------

    private static function validateCpf(string $cpf): bool
    {
        // Reject all-same-digit strings
        if (preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        // First check digit
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $cpf[$i] * (10 - $i);
        }
        $remainder = $sum % 11;
        $first = $remainder < 2 ? 0 : 11 - $remainder;
        if ((int) $cpf[9] !== $first) {
            return false;
        }

        // Second check digit
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += (int) $cpf[$i] * (11 - $i);
        }
        $remainder = $sum % 11;
        $second = $remainder < 2 ? 0 : 11 - $remainder;

        return (int) $cpf[10] === $second;
    }

    private static function validateCnpj(string $cnpj): bool
    {
        // Reject all-same-digit strings
        if (preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }

        // First check digit
        $weights = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $cnpj[$i] * $weights[$i];
        }
        $remainder = $sum % 11;
        $first = $remainder < 2 ? 0 : 11 - $remainder;
        if ((int) $cnpj[12] !== $first) {
            return false;
        }

        // Second check digit
        $weights = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $sum = 0;
        for ($i = 0; $i < 13; $i++) {
            $sum += (int) $cnpj[$i] * $weights[$i];
        }
        $remainder = $sum % 11;
        $second = $remainder < 2 ? 0 : 11 - $remainder;

        return (int) $cnpj[13] === $second;
    }
}
