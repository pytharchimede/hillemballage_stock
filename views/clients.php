<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$assetBase = preg_replace('#/public$#', '', $scriptDir);
$routeBase = $scriptDir;
?>
<h1 style="display:flex;align-items:center;justify-content:space-between;gap:1rem">
    <span>Clients</span>
    <a class="btn" href="<?= $routeBase ?>/clients/new"><i class="fa fa-user-plus"></i> Nouveau client</a>
</h1>
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