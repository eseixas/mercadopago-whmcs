<?php
/**
 * Mercado Pago - Template Renderer
 *
 * Renderiza templates Smarty do módulo, com suporte a override no tema do cliente.
 *
 * Ordem de busca de templates:
 *   1. /templates/{activeTemplate}/seixastec_mercadopago/{template}.tpl  (override do tema)
 *   2. /modules/gateways/seixastec_mercadopago/templates/{template}.tpl  (padrão)
 *
 * @package   SeixasTec\MercadoPago
 * @author    Eduardo Seixas <https://github.com/eseixas>
 * @version   1.0.0
 * @license   GPL-3.0
 */

declare(strict_types=1);

namespace WHMCS\Module\Gateway\SeixastecMercadoPago;

use Smarty;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class TemplateRenderer
{
    private const TEMPLATE_DIR    = __DIR__ . '/templates';
    private const COMPILE_SUBDIR  = 'seixastec_mercadopago';
    private const CACHE_LIFETIME  = 0; // sem cache - dados sempre dinâmicos

    private static ?Smarty $smarty = null;

    /**
     * Renderiza um template e retorna HTML.
     *
     * @param string $template Nome sem extensão (ex: 'pix', 'boleto')
     * @param array  $data     Variáveis disponibilizadas ao template
     */
    public static function render(string $template, array $data = []): string
    {
        $template = preg_replace('/[^a-z0-9_]/i', '', $template);
        if ($template === '') {
            return '<!-- template inválido -->';
        }

        $path = self::resolveTemplatePath($template);
        if ($path === null) {
            return self::renderFallback($template, $data);
        }

        try {
            $smarty = self::getSmarty();
            $smarty->assign($data);
            return (string) $smarty->fetch($path);
        } catch (\Throwable $e) {
            return self::renderError($template, $e->getMessage());
        }
    }

    /**
     * Resolve o caminho do template considerando override no tema ativo.
     */
    private static function resolveTemplatePath(string $template): ?string
    {
        // 1) Override no tema ativo do cliente
        $activeTemplate = self::getActiveClientTemplate();
        if ($activeTemplate !== null) {
            $whmcsRoot = self::getWhmcsRoot();
            $override  = "{$whmcsRoot}/templates/{$activeTemplate}/seixastec_mercadopago/{$template}.tpl";
            if (is_file($override) && is_readable($override)) {
                return $override;
            }
        }

        // 2) Template padrão do módulo
        $default = self::TEMPLATE_DIR . '/' . $template . '.tpl';
        if (is_file($default) && is_readable($default)) {
            return $default;
        }

        return null;
    }

    /**
     * Retorna instância única do Smarty configurada.
     */
    private static function getSmarty(): Smarty
    {
        if (self::$smarty instanceof Smarty) {
            return self::$smarty;
        }

        $smarty = new Smarty();
        $smarty->setTemplateDir(self::TEMPLATE_DIR);
        $smarty->setCompileDir(self::getCompileDir());
        $smarty->setCacheDir(self::getCompileDir());
        $smarty->caching       = false;
        $smarty->cache_lifetime = self::CACHE_LIFETIME;
        $smarty->error_reporting = E_ALL & ~E_NOTICE & ~E_DEPRECATED;
        $smarty->escape_html   = false; // controle manual de escape nos templates

        self::$smarty = $smarty;
        return $smarty;
    }

    /**
     * Diretório de compilação dos templates (templates_c).
     */
    private static function getCompileDir(): string
    {
        $base = self::getWhmcsRoot() . '/templates_c/' . self::COMPILE_SUBDIR;
        if (!is_dir($base)) {
            @mkdir($base, 0755, true);
        }
        return $base;
    }

    /**
     * Detecta o template ativo do cliente atual (se houver).
     */
    private static function getActiveClientTemplate(): ?string
    {
        try {
            if (class_exists(\WHMCS\View\Template\Theme::class)) {
                $theme = new \WHMCS\View\Template\Theme();
                $name  = $theme->getName();
                if (is_string($name) && $name !== '') {
                    return $name;
                }
            }
        } catch (\Throwable $e) {
            // ignora
        }

        // Fallback - lê config padrão
        if (defined('ROOTDIR')) {
            try {
                $sysurl = \WHMCS\Database\Capsule::table('tblconfiguration')
                    ->where('setting', 'Template')
                    ->value('value');
                return is_string($sysurl) && $sysurl !== '' ? $sysurl : null;
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * Retorna raiz do WHMCS.
     */
    private static function getWhmcsRoot(): string
    {
        if (defined('ROOTDIR')) {
            return (string) ROOTDIR;
        }
        return dirname(__DIR__, 3); // /modules/gateways/seixastec_mercadopago/ -> WHMCS root
    }

    /**
     * Fallback caso template não exista.
     */
    private static function renderFallback(string $template, array $data): string
    {
        $msg = htmlspecialchars("Template '{$template}.tpl' não encontrado.", ENT_QUOTES, 'UTF-8');
        return "<div class='alert alert-danger'>{$msg}</div>";
    }

    /**
     * Renderiza erro de Smarty de forma amigável.
     */
    private static function renderError(string $template, string $error): string
    {
        $msg = htmlspecialchars("Erro ao renderizar '{$template}': {$error}", ENT_QUOTES, 'UTF-8');
        return "<div class='alert alert-danger'>{$msg}</div>";
    }
}
