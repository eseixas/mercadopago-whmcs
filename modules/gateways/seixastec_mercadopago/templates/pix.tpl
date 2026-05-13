{*
 * Pix - QR Code + Copia e Cola
 *
 * Variáveis disponíveis:
 *   - $qrCodeBase64 : QR Code em base64 (imagem PNG)
 *   - $qrCode       : Código copia-e-cola
 *   - $expiresAt    : Data de expiração formatada (ou vazio)
 *   - $autoRefresh  : Segundos para auto-refresh (default 15)
 *}

<div class="seixastec-mp-pix text-center" style="padding:15px;">
    <h4 style="margin-bottom:5px;">💸 Pague com Pix</h4>
    <p class="text-muted">
        Aponte a câmera do app do seu banco no QR Code <strong>ou</strong> copie o código abaixo:
    </p>

    {if $qrCodeBase64}
    <div style="margin:20px 0;">
        <img src="data:image/png;base64,{$qrCodeBase64}"
             alt="QR Code Pix"
             style="max-width:280px; width:100%; height:auto;
                    border:1px solid #ddd; padding:10px; background:#fff;
                    border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,.08);">
    </div>
    {/if}

    <div class="input-group" style="max-width:520px; margin:0 auto;">
        <input type="text"
               id="seixastec-pix-code-{$invoiceId|default:'main'}"
               class="form-control"
               value="{$qrCode|escape:'html'}"
               readonly
               onclick="this.select();"
               style="font-family:monospace; font-size:12px;">
        <span class="input-group-btn">
            <button type="button"
                    class="btn btn-success"
                    onclick="seixastecCopyPix('seixastec-pix-code-{$invoiceId|default:'main'}', this);">
                <i class="fa fa-copy"></i> Copiar
            </button>
        </span>
    </div>

    {if $expiresAt}
    <p class="text-muted small" style="margin-top:15px;">
        ⏰ Válido até <strong>{$expiresAt|escape:'html'}</strong>
    </p>
    {/if}

    <p class="text-muted small" style="margin-top:15px;">
        ✅ Após o pagamento, esta página será atualizada automaticamente.
    </p>
</div>

<script>
(function () {
    if (typeof window.seixastecCopyPix !== 'function') {
        window.seixastecCopyPix = function (inputId, btn) {
            var input = document.getElementById(inputId);
            if (!input) return;
            input.select();
            input.setSelectionRange(0, 99999);

            var done = false;
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(input.value).then(function () {
                    seixastecMarkCopied(btn);
                }).catch(function () {
                    fallbackCopy();
                });
            } else {
                fallbackCopy();
            }

            function fallbackCopy() {
                try {
                    document.execCommand('copy');
                    seixastecMarkCopied(btn);
                } catch (e) {}
            }
        };

        window.seixastecMarkCopied = function (btn) {
            var original = btn.innerHTML;
            btn.innerHTML = '✓ Copiado!';
            btn.classList.remove('btn-success');
            btn.classList.add('btn-default');
            setTimeout(function () {
                btn.innerHTML = original;
                btn.classList.add('btn-success');
                btn.classList.remove('btn-default');
            }, 2500);
        };
    }

    // Auto-refresh
    var refreshIn = {$autoRefresh|default:15} * 1000;
    if (refreshIn > 0) {
        setTimeout(function () { window.location.reload(); }, refreshIn);
    }
})();
</script>
