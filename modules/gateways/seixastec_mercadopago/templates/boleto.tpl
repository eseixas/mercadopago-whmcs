{*
 * Boleto Bancário
 *
 * Variáveis disponíveis:
 *   - $url         : URL externa do boleto
 *   - $barcode     : Linha digitável (opcional)
 *   - $expiresAt   : Data de vencimento formatada (opcional)
 *}

<div class="seixastec-mp-boleto text-center" style="padding:15px;">
    <h4 style="margin-bottom:5px;">📄 Boleto Bancário Gerado</h4>
    <p class="text-muted">
        Clique no botão abaixo para visualizar e imprimir seu boleto:
    </p>

    <div style="margin:20px 0;">
        <a href="{$url|escape:'html'}"
           target="_blank"
           rel="noopener noreferrer"
           class="btn btn-primary btn-lg">
            <i class="fa fa-barcode"></i> Visualizar Boleto
        </a>
    </div>

    {if $barcode}
    <div style="max-width:560px; margin:20px auto;">
        <label class="small text-muted" style="display:block; margin-bottom:5px;">
            Linha digitável:
        </label>
        <div class="input-group">
            <input type="text"
                   id="seixastec-boleto-barcode"
                   class="form-control"
                   value="{$barcode|escape:'html'}"
                   readonly
                   onclick="this.select();"
                   style="font-family:monospace; font-size:12px;">
            <span class="input-group-btn">
                <button type="button"
                        class="btn btn-default"
                        onclick="seixastecCopyBoleto(this);">
                    <i class="fa fa-copy"></i> Copiar
                </button>
            </span>
        </div>
    </div>
    {/if}

    {if $expiresAt}
    <p class="text-muted small" style="margin-top:15px;">
        ⏰ Vencimento: <strong>{$expiresAt|escape:'html'}</strong>
    </p>
    {/if}

    <p class="text-muted small" style="margin-top:15px;">
        ℹ️ Após o pagamento, a compensação pode levar até <strong>2 dias úteis</strong>.
    </p>
</div>

<script>
(function () {
    if (typeof window.seixastecCopyBoleto !== 'function') {
        window.seixastecCopyBoleto = function (btn) {
            var input = document.getElementById('seixastec-boleto-barcode');
            if (!input) return;
            input.select();
            try {
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(input.value);
                } else {
                    document.execCommand('copy');
                }
                var original = btn.innerHTML;
                btn.innerHTML = '✓ Copiado!';
                setTimeout(function () { btn.innerHTML = original; }, 2500);
            } catch (e) {}
        };
    }
})();
</script>
