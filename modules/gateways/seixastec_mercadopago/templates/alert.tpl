{*
 * Alerta genérico
 *
 * Variáveis:
 *   - $type    : success | info | warning | danger
 *   - $message : Mensagem (HTML permitido)
 *   - $icon    : Ícone (opcional, ex: ⚠️ ✅ ❌ ℹ️)
 *}

<div class="alert alert-{$type|default:'info'|escape:'html'} seixastec-mp-notice"
     style="margin:15px 0;">
    {if $icon}<strong>{$icon}</strong> {/if}{$message}
</div>
