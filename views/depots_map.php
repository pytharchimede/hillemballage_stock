<h1><i class="fas fa-map-location-dot"></i> Carte des dépôts</h1>
<div class="card" style="padding:12px">
    <div id="map" data-can-edit="1" style="width:100%; height: 70vh; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,.08);"></div>
</div>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css" crossorigin="anonymous" />
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js" crossorigin="anonymous"></script>
<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$assetBase = preg_replace('#/public$#', '', $scriptDir);
?>
<script src="<?= $assetBase ?>/assets/js/depots_map.js"></script>