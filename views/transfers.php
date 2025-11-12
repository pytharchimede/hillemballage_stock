<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$assetBase = preg_replace('#/public$#', '', $scriptDir);
$routeBase = $scriptDir;
?>
<h1>Transfert de stock</h1>
<section class="card">
    <form id="transfer-form" class="stack">
        <div class="form-row">
            <label>Depuis le dépôt
                <select name="from_depot_id" id="from_depot" required></select>
            </label>
            <label>Vers le dépôt
                <select name="to_depot_id" id="to_depot" required></select>
            </label>
        </div>
        <div class="form-row">
            <label>Produit
                <select name="product_id" id="product_id" required></select>
            </label>
            <label>Quantité
                <input type="number" name="quantity" min="1" step="1" required>
            </label>
        </div>
        <div class="actions">
            <button class="btn" type="submit">Transférer</button>
            <a class="btn secondary" href="<?= $routeBase ?>/">Retour</a>
        </div>
        <div id="transfer-msg" class="mt-2"></div>
    </form>
</section>
<script>
    window.ROUTE_BASE = "<?= $routeBase ?>";
</script>
<script src="<?= $assetBase ?>/assets/js/transfers.js"></script>