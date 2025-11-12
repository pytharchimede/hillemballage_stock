<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$assetBase = preg_replace('#/public$#', '', $scriptDir);
$routeBase = $scriptDir;
?>
<h1>Produits</h1>
<p><a class="btn" href="<?= $routeBase ?>/products/new"><i class="fa fa-plus"></i> Nouveau produit</a></p>
<section class="card">
    <h3>Liste</h3>
    <table class="excel" id="products-table">
        <thead>
            <tr>
                <th>Image</th>
                <th>Nom</th>
                <th>SKU</th>
                <th>PU</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</section>
<script>
    window.ROUTE_BASE = "<?= $routeBase ?>";
</script>
<script src="<?= $assetBase ?>/assets/js/products.js"></script>