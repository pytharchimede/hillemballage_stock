<h1>Remises livreurs</h1>

<section class="card" style="margin-bottom:12px">
    <h3>Créer une tournée</h3>
    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end">
        <div>
            <label class="muted">Dépôt</label>
            <select id="sr-depot" class="form-control" style="min-width:220px"></select>
            <div class="muted" style="font-size:11px">Si non visible, votre dépôt est utilisé.</div>
        </div>
        <div>
            <label class="muted">Livreur</label>
            <select id="sr-seller" class="form-control" style="min-width:220px"></select>
        </div>
        <div>
            <label class="muted">Produit</label>
            <select id="sr-product" class="form-control" style="min-width:260px"></select>
        </div>
        <div>
            <label class="muted">Quantité</label>
            <input id="sr-qty" type="number" min="1" class="form-control" style="width:120px" />
        </div>
        <button id="sr-add-item" class="btn small">Ajouter</button>
        <button id="sr-create" class="btn">Créer la tournée</button>
    </div>
    <div id="sr-items" style="margin-top:10px"></div>
    <div id="sr-create-msg" class="muted" style="margin-top:6px"></div>
</section>

<section class="cards grid-2">
    <div class="card">
        <h3>Tournées ouvertes</h3>
        <div id="sr-open">Chargement...</div>
    </div>
    <div class="card">
        <h3>Tournées clôturées (récentes)</h3>
        <div id="sr-closed">Chargement...</div>
    </div>
</section>

<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$assetBase = preg_replace('#/public$#', '', $scriptDir);
?>
<script src="<?= $assetBase ?>/assets/js/rounds.js"></script>