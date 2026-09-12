<?php

declare(strict_types=1);

namespace WHMCS\Module\Gateway\SeixastecMercadoPago;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

final class BrazilAddress
{
    private const UF = [
        'acre' => 'AC', 'alagoas' => 'AL', 'amapa' => 'AP', 'amapá' => 'AP', 'amazonas' => 'AM',
        'bahia' => 'BA', 'ceara' => 'CE', 'ceará' => 'CE', 'distrito federal' => 'DF',
        'espirito santo' => 'ES', 'espírito santo' => 'ES', 'goias' => 'GO', 'goiás' => 'GO',
        'maranhao' => 'MA', 'maranhão' => 'MA', 'mato grosso' => 'MT', 'mato grosso do sul' => 'MS',
        'minas gerais' => 'MG', 'para' => 'PA', 'pará' => 'PA', 'paraiba' => 'PB', 'paraíba' => 'PB',
        'parana' => 'PR', 'paraná' => 'PR', 'pernambuco' => 'PE', 'piaui' => 'PI', 'piauí' => 'PI',
        'rio de janeiro' => 'RJ', 'rio grande do norte' => 'RN', 'rio grande do sul' => 'RS',
        'rondonia' => 'RO', 'rondônia' => 'RO', 'roraima' => 'RR', 'santa catarina' => 'SC',
        'sao paulo' => 'SP', 'são paulo' => 'SP', 'sergipe' => 'SE', 'tocantins' => 'TO',
    ];

    public static function federalUnit(string $state): string
    {
        $state = trim($state);
        if (strlen($state) === 2) {
            return strtoupper($state);
        }

        $key = mb_strtolower($state);

        return self::UF[$key] ?? strtoupper(substr($state, 0, 2));
    }

    public static function streetNumber(string $address): string
    {
        if (preg_match('/(\d+)\s*$/', $address, $m) === 1) {
            return $m[1];
        }

        return 'S/N';
    }
}
