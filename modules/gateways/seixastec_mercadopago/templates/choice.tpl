{*
 * Tela de escolha Pix x Boleto
 *
 * Variáveis:
 *   - $pixUrl     : URL para escolher Pix
 *   - $boletoUrl  : URL para escolher Boleto
 *}

<div class="seixastec-mp-choice text-center" style="padding:20px;">
    <h4 style="margin-bottom:20px;">Como você deseja pagar?</h4>

    <div style="display:flex; gap:15px; justify-content:center; flex-wrap:wrap;">
        <a href="{$pixUrl|escape:'html'}"
           class="btn btn-success btn-lg"
           style="min-width:220px; padding:18px 24px;">
            <div style="font-size:32px; line-height:1;">💸</div>
            <div style="margin-top:8px;"><strong>Pix</strong></div>
            <div style="font-size:11px; opacity:.85;">Aprovação instantânea</div>
        </a>

        <a href="{$boletoUrl|escape:'html'}"
           class="btn btn-primary btn-lg"
           style="min-width:220px; padding:18px 24px;">
            <div style="font-size:32px; line-height:1;">📄</div>
            <div style="margin-top:8px;"><strong>Boleto Bancário</strong></div>
            <div style="font-size:11px; opacity:.85;">Compensa em até 2 dias úteis</div>
        </a>
    </div>
</div>
