<style>
    /* Éviter le flash : masquer par défaut les blocs soumis aux permissions */
    #kpi-receivables,
    #block-revenue,
    #kpi-cash-today,
    #kpi-collections-today,
    #kpi-stock,
    #kpi-stock-valuation,
    #card-low-stock,
    #card-clients,
    #card-orders,
    #card-users,
    #card-top-products {
        display: none;
    }
</style>
<h1 style="margin-bottom:8px">Tableau de bord</h1>

<div class="toolbar" id="dash-filters" style="display:flex;gap:8px;align-items:center;margin-bottom:12px">
    <label for="period-select" class="muted">Période</label>
    <select id="period-select" class="form-control compact" style="width:110px">
        <option value="7">7 jours</option>
        <option value="30" selected>30 jours</option>
        <option value="90">90 jours</option>
    </select>
    <label for="threshold-select" class="muted">Seuil alerte</label>
    <select id="threshold-select" class="form-control compact" style="width:110px">
        <option value="3">≤ 3</option>
        <option value="5" selected>≤ 5</option>
        <option value="10">≤ 10</option>
    </select>
    <label for="depot-select" id="depot-label" class="muted" style="display:none">Dépôt</label>
    <select id="depot-select" class="form-control compact" style="width:220px;display:none"></select>
    <div style="margin-left:auto; display:flex; gap:6px; align-items:center">
        <button class="btn btn-ghost small" id="btn-export-csv" title="Exporter CSV">Exporter CSV</button>
        <button class="btn small" id="btn-export-pdf" title="Exporter PDF">Exporter PDF</button>
    </div>
</div>

<section class="kpi-cards" id="quick-stats">
    <div class="kpi">
        <div class="kpi-icon"><i class="fa-solid fa-coins"></i></div>
        <div class="kpi-body">
            <div class="kpi-label">CA du jour</div>
            <div class="kpi-value" id="qs-ca">—</div>
        </div>
    </div>
    <div class="kpi">
        <div class="kpi-icon"><i class="fa-solid fa-cart-shopping"></i></div>
        <div class="kpi-body">
            <div class="kpi-label">Ventes du jour</div>
            <div class="kpi-value" id="qs-sales">—</div>
        </div>
    </div>
    <div class="kpi">
        <div class="kpi-icon"><i class="fa-solid fa-users"></i></div>
        <div class="kpi-body">
            <div class="kpi-label">Clients actifs (30j)</div>
            <div class="kpi-value" id="qs-clients">—</div>
        </div>
    </div>
    <div class="kpi" id="kpi-receivables">
        <div class="kpi-icon"><i class="fa-solid fa-file-invoice-dollar"></i></div>
        <div class="kpi-body">
            <div class="kpi-label">Encours (créances)</div>
            <div class="kpi-value" id="qs-receivables">—</div>
        </div>
    </div>
    <div class="kpi" id="kpi-stock">
        <div class="kpi-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
        <div class="kpi-body">
            <div class="kpi-label">Stock total</div>
            <div class="kpi-value" id="qs-stock">—</div>
        </div>
    </div>
    <div class="kpi" id="kpi-stock-valuation">
        <div class="kpi-icon"><i class="fa-solid fa-money-bill-wave"></i></div>
        <div class="kpi-body">
            <div class="kpi-label">Valorisation stock</div>
            <div class="kpi-value" id="qs-stock-valuation">—</div>
        </div>
    </div>
    <div class="kpi" id="kpi-rounds-open">
        <div class="kpi-icon"><i class="fa-solid fa-truck-fast"></i></div>
        <div class="kpi-body">
            <div class="kpi-label">Tournées ouvertes</div>
            <div class="kpi-value" id="qs-rounds-open">—</div>
        </div>
    </div>
    <div class="kpi" id="kpi-cash-today">
        <div class="kpi-icon"><i class="fa-solid fa-sack-dollar"></i></div>
        <div class="kpi-body">
            <div class="kpi-label">Cash remis (auj.)</div>
            <div class="kpi-value" id="qs-cash-today">—</div>
        </div>
    </div>
    <div class="kpi" id="kpi-collections-today">
        <div class="kpi-icon"><i class="fa-solid fa-receipt"></i></div>
        <div class="kpi-body">
            <div class="kpi-label">Recouvrement (auj.)</div>
            <div class="kpi-value" id="qs-collections-today">—</div>
        </div>
    </div>
    <button class="btn refresh" id="btn-refresh" title="Rafraîchir">↻ Rafraîchir</button>
    <div class="muted" id="role-hint" style="margin-left:auto"></div>
    <div style="clear:both"></div>
    <div id="sparkline" class="sparkline" style="margin-top:8px"></div>
    <div class="muted" style="font-size:11px">Revenus 7 derniers jours</div>

    <div id="block-revenue" style="margin-top:8px">
        <canvas id="chartRevenue30" height="110"></canvas>
        <div class="muted" style="font-size:11px">Revenus 30 jours</div>
    </div>
</section>

<section class="cards grid-2">
    <div class="card" id="card-top-products">
        <h3><span class="icon"><i class="fa-solid fa-trophy"></i></span> Top produits (30j)</h3>
        <canvas id="chartTopProducts" height="180"></canvas>
    </div>
    <div class="card" id="card-orders">
        <h3><span class="icon"><i class="fa-solid fa-chart-pie"></i></span> Répartition commandes</h3>
        <canvas id="chartOrdersStatus" height="180"></canvas>
    </div>
</section>

<section class="cards grid-2">
    <div class="card" id="card-users">
        <h3><span class="icon"><i class="fa-solid fa-user-tie"></i></span> Vendeurs (30j)</h3>
        <canvas id="chartByUser" height="180"></canvas>
    </div>
    <div class="card" id="card-low-stock">
        <h3 id="low-stock-title"><span class="icon"><i class="fa-solid fa-triangle-exclamation"></i></span> Produits en alerte stock</h3>
        <div id="low-stock">Chargement...</div>
    </div>
</section>

<section class="cards grid-2">
    <div class="card" id="card-clients">
        <h3><span class="icon"><i class="fa-solid fa-hand-holding-dollar"></i></span> Top soldes clients</h3>
        <div id="client-credit">Chargement...</div>
    </div>
    <div class="card">
        <h3><span class="icon"><i class="fa-solid fa-calendar-day"></i></span> Ventes du jour (dépôt)</h3>
        <div id="daily-sales">Chargement...</div>
    </div>
</section>
<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$assetBase = preg_replace('#/public$#', '', $scriptDir);
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="<?= $assetBase ?>/assets/js/dashboard.js"></script>