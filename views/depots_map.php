<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$routeBase = $scriptDir;
$assetBase = preg_replace('#/public$#', '', $scriptDir);
?>

<h1 style="display:flex;justify-content:space-between;align-items:center">
    <span><i class="fas fa-map-location-dot"></i> Carte des dépôts</span>
    <a class="btn" href="<?= $routeBase ?>/depots"><i class="fas fa-list"></i> Liste</a>
</h1>

<div class="card" style="padding:12px; display:flex; flex-direction:column; gap:10px">
    <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center">
        <input id="map-search" type="search" placeholder="Rechercher (nom, code, gérant, adresse)" style="flex:1; min-width:260px">
        <input id="map-radius" type="number" min="0" step="0.5" value="0" style="width:110px" title="Rayon en km" placeholder="Rayon (km)">
        <button class="btn-ghost" id="map-geo-btn" type="button"><i class="fas fa-location-crosshairs"></i> Centrer</button>
        <button class="btn-ghost" id="map-reset-btn" type="button"><i class="fas fa-rotate-left"></i> Réinitialiser</button>
    </div>
    <div style="font-size:12px" class="muted">Rayon = 0 : pas de filtre spatial. Le centre utilisé est la géolocalisation si disponible sinon le centre actuel de la carte.</div>
    <div id="map" data-can-edit="1" style="width:100%; height: 72vh; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,.08);"></div>
</div>

<script>
    window.ROUTE_BASE = "<?= $routeBase ?>";
    window.ASSET_BASE = "<?= $assetBase ?>";
</script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css" crossorigin="anonymous" />
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js" crossorigin="anonymous"></script>
<script src="<?= $assetBase ?>/assets/js/depots_map.js"></script>