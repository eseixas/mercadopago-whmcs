{*
 * Checkout Pro - Botão de redirecionamento
 *
 * Variáveis disponíveis:
 *   - $url        : URL do checkout (init_point ou sandbox_init_point)
 *   - $label      : Label do botão
 *   - $isSandbox  : Boolean
 *}

<div class="seixastec-mp-checkout text-center">
    {if $isSandbox}
        <div class="alert alert-warning" style="margin-bottom:15px;">
            🧪 <strong>Modo Sandbox ativo</strong> — pagamento de teste, sem cobrança real.
        </div>
    {/if}

    <a href="{$url|escape:'html'}"
       target="_blank"
       rel="noopener noreferrer"
       class="btn btn-primary btn-lg">
        <i class="fa fa-credit-card"></i> {$label|escape:'html'}
    </a>

    <p class="text-muted small" style="margin-top:12px;">
        🔒 Você será redirecionado ao ambiente seguro do Mercado Pago.
    </p>

    <div style="margin-top:15px;">
        <img src="https://http2.mlstatic.com/storage/logos-api-admin/8e8b5e30-7b53-11ec-9c12-c30a5cbf7da8-m.svg"
             alt="Métodos de pagamento aceitos" style="max-height:30px; opacity:.7;">
    </div>
</div>
