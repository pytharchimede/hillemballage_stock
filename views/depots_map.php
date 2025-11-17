<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$routeBase = $scriptDir;
$assetBase = preg_replace('#/public$#', '', $scriptDir);
?>

<h1 style="display:flex;justify-content:space-between;align-items:center">
    <span><i class="fas fa-map-location-dot"></i> Carte des dépôts</span>
    <a class="btn" href="<?= $routeBase ?>/depots"><i class="fas fa-list"></i> Liste</a>
</h1>

<div class="card" style="padding:12px">
    <div id="map" data-can-edit="1" style="width:100%; height: 75vh; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,.08);"></div>
</div>

<script>
    window.ROUTE_BASE = "<?= $routeBase ?>";
    window.ASSET_BASE = "<?= $assetBase ?>";
</script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css" crossorigin="anonymous" />
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js" crossorigin="anonymous"></script>
<script src="<?= $assetBase ?>/assets/js/depots_map.js"></script>