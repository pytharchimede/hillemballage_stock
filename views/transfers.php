<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$assetBase = preg_replace('#/public$#', '', $scriptDir);
$routeBase = $scriptDir;
?>
<h1>Transfert de stock</h1>
<section class="card">
    <form id="transfer-form" class="form-modern">
        <div class="form-row">
            <div class="form-field">
                <label for="from_depot">Depuis le dépôt</label>
                <div class="select-search" data-target="from_depot">
                    <input type="text" class="form-control" placeholder="Rechercher un dépôt (nom, code)…" autocomplete="off">
                    <select name="from_depot_id" id="from_depot" class="form-control" required></select>
                </div>
            </div>
            <div class="form-field">
                <label for="to_depot">Vers le dépôt</label>
                <div class="select-search" data-target="to_depot">
                    <input type="text" class="form-control" placeholder="Rechercher un dépôt (nom, code)…" autocomplete="off">
                    <select name="to_depot_id" id="to_depot" class="form-control" required></select>
                </div>
            </div>
        </div>
        <div class="form-row">
            <div class="form-field">
                <label for="product_id">Produit</label>
                <div class="select-search" data-target="product_id">
                    <input type="text" class="form-control" placeholder="Rechercher un produit (nom, SKU)…" autocomplete="off">
                    <select name="product_id" id="product_id" class="form-control" required></select>
                </div>
            </div>
            <div class="form-field">
                <label for="quantity">Quantité</label>
                <input type="number" id="quantity" name="quantity" class="form-control" min="1" step="1" placeholder="Ex: 10" required>
            </div>
        </div>
        <div class="form-actions">
            <button class="btn" type="submit"><i class="fa fa-right-left"></i> Transférer</button>
            <a class="btn secondary" href="<?= $routeBase ?>/">Retour</a>
        </div>
        <div id="transfer-msg" class="mt-2"></div>
    </form>
</section>
<section class="card" style="margin-top:1rem">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap">
        <h2 style="margin:0;font-size:1.1rem">Historique des transferts</h2>
        <div style="opacity:.7;font-size:.9rem">Derniers mouvements</div>
    </div>
    <div id="transfers-history" class="mt-2" style="overflow:auto">
        <table class="table" style="width:100%;min-width:680px">
            <thead>
                <tr>
                    <th style="text-align:left">Date</th>
                    <th style="text-align:left">Produit</th>
                    <th style="text-align:left">De</th>
                    <th style="text-align:left">Vers</th>
                    <th style="text-align:right">Qté</th>
                </tr>
            </thead>
            <tbody id="transfers-history-body">
                <tr>
                    <td colspan="5" style="opacity:.7">Chargement…</td>
                </tr>
            </tbody>
        </table>
    </div>
    <div class="mt-2" id="transfers-history-empty" style="display:none; opacity:.7">Aucun transfert trouvé.</div>
</section>
<script>
    window.ROUTE_BASE = "<?= $routeBase ?>";
</script>
<script src="<?= $assetBase ?>/assets/js/transfers.js"></script>