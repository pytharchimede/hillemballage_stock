<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$assetBase = preg_replace('#/public$#', '', $scriptDir);
$routeBase = $scriptDir;
?>
<h1>Produits</h1>
<p style="display:flex; gap:.5rem; align-items:center; flex-wrap:wrap">
    <a class="btn" id="btn-new-product" data-entity="products" data-action="edit" href="<?= $routeBase ?>/products/new"><i class="fa fa-plus"></i> Nouveau produit</a>
    <button class="btn secondary" id="fix-img-btn" title="Normaliser les chemins d'images (admin)" data-role="admin-only"><i class="fa fa-wrench"></i> Normaliser images</button>
    <span id="fix-img-status" class="muted" style="display:none"></span>
</p>
<section class="card">
    <div class="cards-grid" id="products-grid"></div>
    <div id="products-empty" class="muted" style="display:none;padding:.75rem">Aucun produit trouvé.</div>
</section>
<script>
    window.ROUTE_BASE = "<?= $routeBase ?>";
</script>
<script src="<?= $assetBase ?>/assets/js/products.js"></script>