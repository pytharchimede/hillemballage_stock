<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$assetBase = preg_replace('#/public$#', '', $scriptDir);
$routeBase = $scriptDir;
?>
<h1>Nouveau produit</h1>
<section class="card">
    <form id="product-form" class="stack" enctype="multipart/form-data" data-mode="create">
        <div class="form-row">
            <label>Nom
                <input type="text" name="name" id="prod-name" required placeholder="Ex: Bouteille 1L">
            </label>
            <label>SKU généré
                <input type="text" name="sku" id="prod-sku" readonly class="readonly" placeholder="Auto-généré">
            </label>
            <label>Prix unitaire
                <input type="number" name="unit_price" min="0" step="1" required>
            </label>
            <label>Image
                <div class="dropzone" id="image-drop">
                    <input type="file" name="image" id="image-input" accept="image/*" hidden>
                    <p class="dz-label">Glissez-déposez l'image ou cliquez</p>
                    <img id="image-preview" alt="Prévisualisation" style="display:none;max-height:120px" />
                </div>
            </label>
        </div>
        <div class="form-row">
            <label>Quantité initiale (optionnel)
                <input type="number" name="initial_quantity" min="0" step="1" placeholder="0">
            </label>
            <label>Dépôt
                <div class="select-search" id="depot-select-wrapper">
                    <input type="text" id="depot-search" placeholder="Rechercher dépôt..." autocomplete="off" />
                    <select name="depot_id" id="depot-select" size="4" required></select>
                </div>
            </label>
        </div>
        <div class="actions">
            <button class="btn" type="submit">Créer</button>
            <a class="btn secondary" href="<?= $routeBase ?>/products">Annuler</a>
        </div>
    </form>
</section>
<script>
    window.ROUTE_BASE = "<?= $routeBase ?>";
</script>
<script src="<?= $assetBase ?>/assets/js/product_form.js"></script>