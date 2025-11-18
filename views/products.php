<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$assetBase = preg_replace('#/public$#', '', $scriptDir);
$routeBase = $scriptDir;
?>
<h1>Produits</h1>
<section class="card" style="margin-bottom:1rem">
    <form id="products-search" class="stack" style="gap:.5rem">
        <div style="display:flex; flex-wrap:wrap; gap:.5rem; align-items:center">
            <input type="text" id="ps-q" class="form-control" placeholder="Rechercher (nom, SKU)" style="min-width:280px;flex:1" />
            <div id="ps-depot-wrap" style="min-width:240px; display:flex; gap:.4rem; align-items:center">
                <select id="ps-depot" class="form-control select-search" style="min-width:240px"></select>
                <input type="text" id="ps-depot-filter" class="form-control compact" placeholder="Filtrer dépôts" style="width:160px" />
            </div>
            <label style="display:flex; gap:.3rem; align-items:center">
                <input type="checkbox" id="ps-only-in-stock" /> Seulement en stock
            </label>
        </div>
        <div style="display:flex; flex-wrap:wrap; gap:.5rem; align-items:center">
            <button type="button" id="ps-export-csv" class="btn secondary"><i class="fa fa-file-excel-o"></i> Exporter Excel</button>
            <button type="button" id="ps-export-pdf" class="btn secondary"><i class="fa fa-file-pdf-o"></i> Exporter PDF</button>
            <a class="btn" id="btn-new-product" data-entity="products" data-action="edit" href="<?= $routeBase ?>/products/new"><i class="fa fa-plus"></i> Nouveau produit</a>
            <button class="btn secondary" id="fix-img-btn" title="Normaliser les chemins d'images (admin)" data-role="admin-only"><i class="fa fa-wrench"></i> Normaliser images</button>
            <span id="fix-img-status" class="muted" style="display:none"></span>
        </div>
    </form>
    <div class="muted" id="ps-hint" style="margin-top:.25rem"></div>
    <script>
        window.ROUTE_BASE = "<?= $routeBase ?>";
    </script>
</section>
<section class="card">
    <div class="cards-grid" id="products-grid"></div>
    <div id="products-empty" class="muted" style="display:none;padding:.75rem">Aucun produit trouvé.</div>
</section>
<script src="<?= $assetBase ?>/assets/js/products.js"></script>