<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$assetBase = preg_replace('#/public$#', '', $scriptDir);
$routeBase = $scriptDir;
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
?>
<h1>Détail produit</h1>
<section class="card">
    <div id="product-view" data-id="<?= $id ?>" class="stack" style="gap:1rem"></div>
</section>
<script>
    window.ROUTE_BASE = "<?= $routeBase ?>";
</script>
<script src="<?= $assetBase ?>/assets/js/product_view.js"></script>