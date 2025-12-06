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
        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
            <h3 style="margin:0">Tournées ouvertes</h3>
            <div>
                <button id="sr-export-open-csv" class="btn secondary" type="button">CSV</button>
                <button id="sr-export-open-pdf" class="btn secondary" type="button">PDF</button>
            </div>
        </div>
        <form id="sr-search-open" style="margin:10px 0;display:flex;flex-wrap:wrap;gap:8px;align-items:end">
            <div>
                <label class="muted" for="sr-search-open-depot">Dépôt</label>
                <select id="sr-search-open-depot" class="form-control" style="min-width:200px"></select>
            </div>
            <div>
                <label class="muted" for="sr-search-open-seller">Livreur</label>
                <select id="sr-search-open-seller" class="form-control" style="min-width:200px"></select>
            </div>
            <div>
                <label class="muted" for="sr-search-open-from">Du</label>
                <input type="date" id="sr-search-open-from" class="form-control" />
            </div>
            <div>
                <label class="muted" for="sr-search-open-to">Au</label>
                <input type="date" id="sr-search-open-to" class="form-control" />
            </div>
            <button type="submit" class="btn">Rechercher</button>
        </form>
        <div id="sr-open" class="cards-list">Chargement...</div>
    </div>
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
            <h3 style="margin:0">Tournées clôturées</h3>
            <div>
                <button id="sr-export-closed-csv" class="btn secondary" type="button">CSV</button>
                <button id="sr-export-closed-pdf" class="btn secondary" type="button">PDF</button>
            </div>
        </div>
        <form id="sr-search-closed" style="margin:10px 0;display:flex;flex-wrap:wrap;gap:8px;align-items:end">
            <div>
                <label class="muted" for="sr-search-closed-depot">Dépôt</label>
                <select id="sr-search-closed-depot" class="form-control" style="min-width:200px"></select>
            </div>
            <div>
                <label class="muted" for="sr-search-closed-seller">Livreur</label>
                <select id="sr-search-closed-seller" class="form-control" style="min-width:200px"></select>
            </div>
            <div>
                <label class="muted" for="sr-search-closed-from">Du</label>
                <input type="date" id="sr-search-closed-from" class="form-control" />
            </div>
            <div>
                <label class="muted" for="sr-search-closed-to">Au</label>
                <input type="date" id="sr-search-closed-to" class="form-control" />
            </div>
            <button type="submit" class="btn">Rechercher</button>
        </form>
        <div id="sr-closed" class="cards-list">Chargement...</div>
    </div>
</section>

<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$assetBase = preg_replace('#/public$#', '', $scriptDir);
?>
<script src="<?= $assetBase ?>/assets/js/rounds.js"></script>