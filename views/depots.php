<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$routeBase = $scriptDir;
$assetBase = preg_replace('#/public$#', '', $scriptDir);
?>
<h1 style="display:flex;justify-content:space-between;align-items:center">
    <span><i class="fas fa-warehouse"></i> Dépôts</span>
    <a class="btn" href="<?= $routeBase ?>/depots/new"><i class="fas fa-plus-circle"></i> Nouveau dépôt</a>
</h1>

<section class="card">
    <div style="display:flex; gap:10px; align-items:center; margin-bottom:10px">
        <input id="depots-search" type="search" placeholder="Rechercher (nom, code, gérant, adresse)" style="flex:1">
    </div>
    <div id="depots-empty" style="display:none">Aucun dépôt pour l'instant.</div>
    <div id="depots-grid" class="cards-grid"></div>
</section>

<script>
    window.ROUTE_BASE = "<?= $routeBase ?>";
    window.ASSET_BASE = "<?= $assetBase ?>";
</script>
<script src="<?= $assetBase ?>/assets/js/depots.js"></script>