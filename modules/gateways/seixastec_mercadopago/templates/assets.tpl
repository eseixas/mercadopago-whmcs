{*
 * CSS global do módulo - injetado uma vez por página
 *}

<style>
.seixastec-mp-pix img,
.seixastec-mp-boleto img { transition: transform .2s; }
.seixastec-mp-pix img:hover { transform: scale(1.03); }

.seixastec-mp-pix .input-group,
.seixastec-mp-boleto .input-group {
    box-shadow: 0 2px 8px rgba(0,0,0,.08);
    border-radius: 6px;
    overflow: hidden;
}

.seixastec-mp-checkout .btn-primary,
.seixastec-mp-choice .btn {
    box-shadow: 0 2px 6px rgba(0,0,0,.12);
    transition: transform .15s, box-shadow .15s;
}
.seixastec-mp-checkout .btn-primary:hover,
.seixastec-mp-choice .btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,.18);
}

.alert.seixastec-mp-notice {
    border-left: 4px solid #009ee3;
}
.alert.alert-success.seixastec-mp-notice  { border-left-color: #5cb85c; }
.alert.alert-warning.seixastec-mp-notice  { border-left-color: #f0ad4e; }
.alert.alert-danger.seixastec-mp-notice   { border-left-color: #d9534f; }

@media (max-width: 480px) {
    .seixastec-mp-pix img { max-width: 100% !important; }
    .seixastec-mp-choice .btn { width: 100%; }
}
</style>
