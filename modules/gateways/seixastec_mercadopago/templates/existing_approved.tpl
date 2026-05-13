{*
 * Mensagem quando já existe pagamento aprovado
 *}

<div class="alert alert-success seixastec-mp-notice" style="margin:15px 0;">
    <strong>✅ Pagamento aprovado!</strong>
    Em instantes a fatura será marcada como paga automaticamente.
    {if $paymentId}
    <br><small class="text-muted">ID do pagamento: <code>{$paymentId|escape:'html'}</code></small>
    {/if}
</div>
