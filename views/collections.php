<h1>Recouvrement</h1>

<section class="card" style="margin-bottom:12px">
    <h3>Filtres</h3>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <select id="rc-depot" class="form-control" style="min-width:200px"></select>
        <select id="rc-user" class="form-control" style="min-width:200px"></select>
        <input id="rc-from" type="date" class="form-control compact">
        <input id="rc-to" type="date" class="form-control compact">
        <button id="rc-export-csv" class="btn small">Export CSV</button>
        <button id="rc-export-pdf" class="btn small">Export PDF</button>
    </div>
    <div class="muted" style="margin-top:6px">Sélectionnez un dépôt et/ou un agent pour exporter la liste des créances (par client) dans votre périmètre.</div>
    <div id="rc-scope-hint" class="muted" style="margin-top:4px"></div>
</section>

<section class="card" style="margin-bottom:12px">
    <h3>Choisir un client</h3>
    <div style="display:flex;gap:8px;align-items:center">
        <select id="rc-client" class="form-control" style="min-width:260px"></select>
        <button id="rc-load" class="btn">Charger</button>
        <button id="rc-ledger-csv" class="btn secondary small">Exporter client CSV</button>
        <button id="rc-ledger-pdf" class="btn secondary small">Exporter client PDF</button>
    </div>
</section>

<section class="card">
    <h3>Créances et paiements</h3>
    <div id="rc-client-info" class="muted" style="margin-bottom:6px"></div>
    <div id="rc-sales">Sélectionnez un client</div>

</section>

<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$assetBase = preg_replace('#/public$#', '', $scriptDir);
?>
<script src="<?= $assetBase ?>/assets/js/collections.js"></script>