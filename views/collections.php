<h1>Recouvrement</h1>

<section class="card" style="margin-bottom:12px">
    <h3>Choisir un client</h3>
    <div style="display:flex;gap:8px;align-items:center">
        <select id="rc-client" class="form-control" style="min-width:260px"></select>
        <button id="rc-load" class="btn">Charger</button>
    </div>
</section>

<section class="card">
    <h3>Créances et paiements</h3>
    <div id="rc-sales">Sélectionnez un client</div>
</section>

<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$assetBase = preg_replace('#/public$#', '', $scriptDir);
?>
<script src="<?= $assetBase ?>/assets/js/collections.js"></script>