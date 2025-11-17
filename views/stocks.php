<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$routeBase = $scriptDir;
$assetBase = preg_replace('#/public$#', '', $scriptDir);
?>
<h1 style="display:flex;justify-content:space-between;align-items:center">
    <span><i class="fas fa-boxes-stacked"></i> Stocks par dépôt</span>
    <a class="btn" href="<?= $routeBase ?>/products"><i class="fas fa-list"></i> Produits</a>
</h1>

<section class="card" style="display:flex; flex-direction:column; gap:10px">
    <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center">
        <label>Produit
            <select id="stock-product" style="min-width:260px"></select>
        </label>
        <button class="btn" id="refresh-stock" type="button"><i class="fas fa-rotate"></i> Actualiser</button>
    </div>
    <div class="muted" style="font-size:12px">Cliquez sur Entrée/Transférer pour ajuster les stocks directement.</div>
    <div style="overflow:auto">
        <table class="excel" id="stocks-table" style="min-width:680px">
            <thead>
                <tr>
                    <th>Dépôt</th>
                    <th>Code</th>
                    <th>Quantité</th>
                    <th style="text-align:left">Actions</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</section>
<script>
    window.ROUTE_BASE = "<?= $routeBase ?>";
</script>
<script src="<?= $assetBase ?>/assets/js/stocks.js"></script>