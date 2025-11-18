<h1 style="margin-bottom:8px">Ventes</h1>

<div class="toolbar" style="display:flex;gap:8px;align-items:center;margin-bottom:12px">
    <label class="muted" for="from">Du</label>
    <input type="date" id="from" class="form-control compact" style="width:160px">
    <label class="muted" for="to">Au</label>
    <input type="date" id="to" class="form-control compact" style="width:160px">
    <label class="muted" for="client-select">Client</label>
    <select id="client-select" class="form-control" style="min-width:240px">
        <option value="">Tous</option>
    </select>
    <button class="btn small" id="btn-filter">Filtrer</button>
</div>

<div id="sales-table">Chargement...</div>

<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$assetBase = preg_replace('#/public$#', '', $scriptDir);
?>
<script src="<?= $assetBase ?>/assets/js/sales.js"></script>