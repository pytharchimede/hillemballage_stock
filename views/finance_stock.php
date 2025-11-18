<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$routeBase = $scriptDir; // ex: /hill_new/public
$assetBase = preg_replace('#/public$#', '', $scriptDir);
?>
<div class="page">
    <div class="page-header">
        <h1><i class="fa fa-scale-balanced"></i> Point financier & stock</h1>
        <div class="muted">Vue consolidée des stocks par dépôt et des soldes clients</div>
    </div>

    <div class="toolbar" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap">
        <div>
            <label class="muted">Dépôt</label>
            <select id="fs-depot" class="form-control compact" style="width:220px"></select>
        </div>
        <div>
            <label class="muted">De</label>
            <input type="date" id="fs-from" class="form-control compact" />
        </div>
        <div>
            <label class="muted">À</label>
            <input type="date" id="fs-to" class="form-control compact" />
        </div>
        <div style="display:flex;gap:6px">
            <button class="btn small" id="fs-apply"><i class="fa fa-filter"></i> Appliquer</button>
            <button class="btn btn-ghost small" id="fs-reset"><i class="fa fa-eraser"></i> Réinitialiser</button>
        </div>
        <div style="margin-left:auto;display:flex;gap:6px">
            <button class="btn btn-ghost small" id="fs-export-csv"><i class="fa fa-file-csv"></i> Export CSV</button>
            <button class="btn small" id="fs-export-pdf"><i class="fa fa-file-pdf"></i> Export PDF</button>
        </div>
    </div>

    <section class="cards grid-2">
        <div class="card">
            <h3><span class="icon"><i class="fa fa-warehouse"></i></span> Stocks par dépôt</h3>
            <div id="fs-by-depot">Chargement...</div>
        </div>
        <div class="card">
            <h3><span class="icon"><i class="fa fa-hand-holding-dollar"></i></span> Soldes clients</h3>
            <div id="fs-clients">Chargement...</div>
        </div>
    </section>
</div>
<script>
    window.ROUTE_BASE = <?= json_encode($routeBase) ?>;
</script>
<script src="<?= $assetBase ?>/assets/js/finance_stock.js"></script>