<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$routeBase = $scriptDir;
$assetBase = preg_replace('#/public$#', '', $scriptDir);
?>
<div class="page">
    <div class="page-header">
        <h1><i class="fa fa-tools"></i> Corrections des tournées (Admin)</h1>
        <div class="muted">Modifier quantités vendues/retournées, montants versés et recouvrements; supprimer une vente avec impacts comptables.</div>
    </div>

    <div class="toolbar">
        <div class="filter-row">
            <label>ID tournée</label>
            <input type="number" id="round-id" class="form-control compact" placeholder="ID" />
        </div>
        <div class="filter-row">
            <button id="btn-load-round" class="btn"><i class="fa fa-sync"></i> Charger</button>
            <button id="btn-apply" class="btn btn-outline-main"><i class="fa fa-save"></i> Appliquer corrections</button>
        </div>
    </div>

    <div id="round-summary" class="muted" style="margin:.5rem 0"></div>

    <div class="grid-2">
        <div>
            <h3>Ventes</h3>
            <div id="sales-grid" class="table-responsive"></div>
        </div>
        <div>
            <h3>Articles</h3>
            <div id="items-grid" class="table-responsive"></div>
            <h3 style="margin-top:1rem">Recouvrements</h3>
            <div id="collections-grid" class="table-responsive"></div>
        </div>
    </div>
</div>

<script>
    window.ROUTE_BASE = <?= json_encode($routeBase) ?>;
</script>
<script src="<?= $assetBase ?>/assets/js/seller_rounds_admin.js"></script>