<h1>Dashboard</h1>
<section class="quick-stats" id="quick-stats">
    <div class="qs-item">
        <div class="qs-label">CA du jour</div>
        <div class="qs-value" id="qs-ca">—</div>
    </div>
    <div class="qs-item">
        <div class="qs-label">Ventes du jour</div>
        <div class="qs-value" id="qs-sales">—</div>
    </div>
    <div class="qs-item">
        <div class="qs-label">Clients actifs</div>
        <div class="qs-value" id="qs-clients">—</div>
    </div>
    <button class="btn refresh" id="btn-refresh" title="Rafraîchir">↻ Rafraîchir</button>
</section>
<section class="cards">
    <div class="card">
        <h3>Stock global</h3>
        <div id="stock-summary">Chargement...</div>
    </div>
    <div class="card">
        <h3>Top soldes clients</h3>
        <div id="client-credit">Chargement...</div>
    </div>
    <div class="card">
        <h3>Ventes du jour</h3>
        <div id="sparkline" class="sparkline"> </div>
        <div id="daily-sales">Chargement...</div>
    </div>
</section>
<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$assetBase = preg_replace('#/public$#', '', $scriptDir);
?>
<script src="<?= $assetBase ?>/assets/js/dashboard.js"></script>