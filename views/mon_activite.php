<h1>Mon activité</h1>

<section class="card" style="margin-bottom:12px">
    <h3 style="margin:0">Recherche</h3>
    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end">
        <div>
            <label class="muted">Statut</label>
            <select id="ma-status" class="form-control" style="min-width:140px">
                <option value="open">Ouvertes</option>
                <option value="closed" selected>Clôturées</option>
            </select>
        </div>
        <div>
            <label class="muted">Du</label>
            <input id="ma-from" type="date" class="form-control" />
        </div>
        <div>
            <label class="muted">Au</label>
            <input id="ma-to" type="date" class="form-control" />
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <button id="ma-search" class="btn">Rechercher</button>
            <button id="ma-export-csv" class="btn-ghost">Exporter (CSV)</button>
            <button id="ma-export-pdf" class="btn-ghost">Exporter (PDF)</button>
            <div style="margin-left:auto;display:flex;gap:8px;align-items:center">
                <input id="ma-q" type="text" class="form-control" placeholder="Recherche rapide (#id, nom)" style="max-width:220px" />
                <select id="ma-view-mode" class="form-control" style="min-width:140px">
                    <option value="grid" selected>Grille</option>
                    <option value="carousel">Carrousel</option>
                </select>
            </div>
        </div>
    </div>
</section>

<section class="cards grid-2">
    <div class="card">
        <h3 style="margin:0">Tournées</h3>
        <div id="ma-rounds" style="margin-top:8px">Chargement...</div>
    </div>

    <div class="card">
        <h3>Résumé de la tournée</h3>
        <div id="ma-round-detail">Sélectionnez une tournée pour voir le détail.</div>
    </div>
</section>

<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$assetBase = preg_replace('#/public$#', '', $scriptDir);
$apiBase = $assetBase . '/public';
?>
<script>
    window.API_BASE = "<?= addslashes($apiBase) ?>";
</script>

<script src="<?= $assetBase ?>/assets/js/mon_activite.js"></script>