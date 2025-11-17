<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$assetBase = preg_replace('#/public$#', '', $scriptDir);
$routeBase = $scriptDir;
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
?>
<h1>Modifier le produit</h1>
<section class="card">
    <form id="product-form" class="stack" enctype="multipart/form-data" data-mode="edit" data-product-id="<?= $id ?>">
        <div class="form-row">
            <label>Nom
                <input type="text" name="name" required>
            </label>
            <label>SKU
                <input type="text" name="sku" required>
            </label>
            <label>Prix unitaire
                <input type="number" name="unit_price" min="0" step="1" required>
            </label>
            <label>Description
                <textarea name="description" id="prod-desc" rows="3" placeholder="Description du produit (optionnel)"></textarea>
            </label>
            <label>Image (remplacer)
                <input type="file" name="image" accept="image/*">
            </label>
        </div>
        <div class="actions">
            <button class="btn" type="submit">Enregistrer</button>
            <a class="btn secondary" href="<?= $routeBase ?>/products">Annuler</a>
        </div>
    </form>
</section>
<script>
    window.ROUTE_BASE = "<?= $routeBase ?>";
</script>
<script src="<?= $assetBase ?>/assets/js/product_form.js"></script>