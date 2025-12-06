<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$assetBase = preg_replace('#/public$#', '', $scriptDir);
$routeBase = $scriptDir;
?>
<h1 style="display:flex;align-items:center;justify-content:space-between;gap:1rem">
    <span>Clients</span>
    <a class="btn" href="<?= $routeBase ?>/clients/new"><i class="fa fa-user-plus"></i> Nouveau client</a>
</h1>
<form id="clients-search-form" class="toolbar" method="get" action="#" style="display:flex;gap:.5rem;align-items:center;margin:.5rem 0 1rem 0;">
    <input type="text" id="clients-search-q" name="q" placeholder="Rechercher (nom, téléphone)" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" style="flex:1;padding:.5rem .6rem">
    <button type="submit" class="btn">Rechercher</button>
    <span style="flex:0 0 .5rem"></span>
    <a href="#" id="clients-export-csv" class="btn secondary" title="Exporter CSV"><i class="fa fa-file-csv"></i> CSV</a>
    <a href="#" id="clients-export-xls" class="btn secondary" title="Exporter Excel"><i class="fa fa-file-excel"></i> Excel</a>
    <a href="#" id="clients-export-pdf" class="btn secondary" title="Exporter PDF"><i class="fa fa-file-pdf"></i> PDF</a>
</form>
<section class="card">
    <h3 style="margin-top:0">Liste</h3>
    <div id="clients-grid" class="cards-grid"></div>
    <div id="clients-empty" class="muted" style="display:none;padding:.75rem">Aucun client trouvé.</div>

    <!-- Ancien tableau masqué (compat) -->
    <table style="display:none" class="excel" id="clients-table">
        <tbody></tbody>
    </table>
</section>
<script>
    window.ROUTE_BASE = "<?= $routeBase ?>";
</script>
<script src="<?= $assetBase ?>/assets/js/clients.js"></script>