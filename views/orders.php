<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$assetBase = preg_replace('#/public$#', '', $scriptDir);
?>
<h1 style="display:flex;align-items:center;justify-content:space-between;gap:1rem">
    <span>Commandes (Approvisionnement)</span>
    <span style="display:flex;gap:.5rem">
        <a class="btn" href="<?= $scriptDir ?>/orders/new"><i class="fa fa-plus"></i> Nouvelle commande</a>
    </span>
</h1>

<section class="card">
    <h3>Filtres</h3>
    <div class="form-row" style="align-items:flex-end">
        <div class="form-field" style="min-width:200px">
            <label for="status-filter">Statut</label>
            <select id="status-filter" class="form-control">
                <option value="">Tous</option>
                <option value="draft">Brouillon</option>
                <option value="ordered">Commandé</option>
                <option value="received">Reçu</option>
            </select>
        </div>
        <div class="form-field" style="min-width:240px">
            <label for="q-filter">Recherche</label>
            <input id="q-filter" class="form-control" type="text" placeholder="Référence ou fournisseur…" />
        </div>
        <div class="form-field" style="min-width:220px">
            <label for="depot-filter">Dépôt réception</label>
            <select id="depot-filter" class="form-control"></select>
        </div>
        <div class="form-field">
            <button id="btn-reset" type="button" class="btn secondary"><i class="fa fa-rotate"></i> Réinitialiser</button>
        </div>
    </div>
    <small class="muted">Astuce: filtrez par statut, tapez une référence.</small>
</section>

<section class="card">
    <h3>Liste des commandes</h3>
    <div id="orders-grid" class="cards-grid"></div>
    <div id="orders-empty" class="muted" style="display:none;padding:.75rem">Aucune commande trouvée.</div>
</section>

<script>
    window.ROUTE_BASE = "<?= $scriptDir ?>";
    window.ASSET_BASE = "<?= $assetBase ?>";
</script>
<script src="<?= $assetBase ?>/assets/js/orders.js"></script>
<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$assetBase = preg_replace('#/public$#', '', $scriptDir);
?>

<script src="<?= $assetBase ?>/assets/js/orders.js"></script>