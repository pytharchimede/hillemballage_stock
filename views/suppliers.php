<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$assetBase = preg_replace('#/public$#', '', $scriptDir);
$routeBase = $scriptDir;
?>
<h1 style="display:flex;align-items:center;justify-content:space-between;gap:1rem">
    <span>Fournisseurs</span>
    <a class="btn" href="<?= $routeBase ?>/suppliers/new" id="supplier-new"><i class="fa fa-industry"></i> Nouveau fournisseur</a>
</h1>
<form id="suppliers-search-form" class="toolbar" method="get" action="#" style="display:flex;gap:.5rem;align-items:center;margin:.5rem 0 1rem 0;">
    <input type="text" id="suppliers-search-q" name="q" placeholder="Rechercher (nom, email, téléphone, ville, pays)" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" style="flex:1;padding:.5rem .6rem">
    <button type="submit" class="btn">Rechercher</button>
    <span style="flex:0 0 .5rem"></span>
    <a href="#" id="suppliers-export-csv" class="btn secondary" title="Exporter CSV"><i class="fa fa-file-csv"></i> CSV</a>
    <a href="#" id="suppliers-export-pdf" class="btn secondary" title="Exporter PDF"><i class="fa fa-file-pdf"></i> PDF</a>
</form>
<section class="card">
    <h3 style="margin-top:0">Liste</h3>
    <div id="suppliers-grid" class="cards-grid"></div>
    <div id="suppliers-empty" class="muted" style="display:none;padding:.75rem">Aucun fournisseur trouvé.</div>

    <table style="display:none" class="excel" id="suppliers-table">
        <tbody></tbody>
    </table>
</section>

<section class="card">
    <h3 style="margin-top:0">Localisation fournisseur</h3>
    <p class="muted">Sélectionnez un fournisseur pour afficher/mettre à jour sa localisation. Utilise Leaflet comme pour les dépôts.</p>
    <div style="display:flex;gap:.5rem;margin-bottom:.5rem">
        <input type="text" id="supplier-geo-search" placeholder="Rechercher une adresse / lieu" style="flex:1;padding:.4rem .5rem">
        <div id="supplier-geo-suggestions" class="card" style="position:absolute;z-index:10;display:none;max-height:180px;overflow:auto;padding:.25rem"></div>
    </div>
    <div id="supplier-map" style="height:420px;border:1px solid #ddd;border-radius:8px;"></div>
    <div style="display:flex;gap:.5rem;margin-top:.5rem">
        <input type="text" id="supplier-lat" placeholder="Latitude" style="flex:1;padding:.4rem .5rem">
        <input type="text" id="supplier-lon" placeholder="Longitude" style="flex:1;padding:.4rem .5rem">
        <button id="supplier-save-geo" class="btn">Enregistrer géolocalisation</button>
    </div>
</section>

<script>
    window.ROUTE_BASE = "<?= $routeBase ?>";
    window.ASSET_BASE = "<?= $assetBase ?>";
</script>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4Nx5C8l7S/jTnP6vBJEa0r4Y6kLQvZ8S8QG8Z8CmoY=" crossorigin="" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9CO0z7A0JfX2V0vZ1yuwuHcuyrQ0SYJ8S3I=" crossorigin=""></script>
<script src="<?= $assetBase ?>/assets/js/suppliers.js"></script>