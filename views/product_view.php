<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$assetBase = preg_replace('#/public$#', '', $scriptDir);
$routeBase = $scriptDir;
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
?>
<h1>Détail produit</h1>
<section class="card">
    <div class="stack" style="gap:1rem">
        <div style="display:flex; gap:.5rem; align-items:center; justify-content:flex-end">
            <a class="btn secondary" target="_blank" href="<?= $routeBase ?>/api/v1/products/<?= $id ?>/export"><i class="fa fa-id-card-o"></i> Fiche produit (PDF)</a>
        </div>
        <div id="product-view" data-id="<?= $id ?>" class="stack" style="gap:1rem"></div>
    </div>
</section>
<script>
    window.ROUTE_BASE = "<?= $routeBase ?>";
</script>
<script src="<?= $assetBase ?>/assets/js/product_view.js"></script>