<h1>Vente rapide</h1>

<section class="card" style="margin-bottom:12px">
    <h3>Nouvelle vente (mobile-first)</h3>
    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">
        <div>
            <label class="muted">Dépôt</label>
            <select id="sq-depot" class="form-control" style="min-width:220px"></select>
        </div>
        <div>
            <label class="muted">Client</label>
            <div style="display:flex;gap:8px;align-items:center">
                <button id="sq-client-open" class="btn-ghost">Sélectionner / Créer</button>
                <span id="sq-client-selected" class="muted">Aucun client sélectionné</span>
            </div>
        </div>
        <div>
            <label class="muted">Tout cash</label>
            <input id="sq-cash-all" type="checkbox" class="form-check" />
        </div>
        <div>
            <label class="muted">Montant payé</label>
            <input id="sq-paid" type="number" min="0" class="form-control" style="width:160px" />
        </div>
        <button id="sq-submit" class="btn">Valider la vente</button>
    </div>
    <div class="muted" style="margin-top:6px">Sélectionnez les produits ci-dessous, ajustez les quantités et validez.</div>
</section>

<section class="cards grid-2">
    <div class="card">
        <div style="display:flex;gap:8px;align-items:center;justify-content:space-between">
            <h3 style="margin:0">Produits</h3>
            <input id="sq-search" class="form-control" placeholder="Rechercher..." style="max-width:220px" />
        </div>
        <div id="sq-products" style="margin-top:8px">Chargement...</div>
    </div>
    <div class="card">
        <h3>Panier</h3>
        <div id="sq-cart"></div>
        <div style="margin-top:10px;display:flex;justify-content:space-between;align-items:center">
            <div class="muted">Total</div>
            <div id="sq-total" style="font-weight:bold;font-size:18px">0</div>
        </div>
    </div>
</section>

<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$assetBase = preg_replace('#/public$#', '', $scriptDir);
?>
<script src="<?= $assetBase ?>/assets/js/sales_quick.js"></script>