<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$routeBase = $scriptDir; // ex: /hill_new/public
$assetBase = preg_replace('#/public$#', '', $scriptDir);
?>
<div class="page">
    <div class="page-header">
        <h1><i class="fa fa-clipboard-list"></i> Journal d'audit</h1>
        <div class="muted">Historique des actions (connexion, visualisation, ajout, modification, suppression, export)</div>
    </div>

    <div class="filters toolbar">
        <div class="filter-row">
            <label>Action</label>
            <select id="log-action" class="form-control compact">
                <option value="">Toutes</option>
                <option value="login">Connexion</option>
                <option value="view">Visualisation</option>
                <option value="add">Ajout</option>
                <option value="modify">Modification</option>
                <option value="delete">Suppression</option>
                <option value="export">Export</option>
            </select>
        </div>
        <div class="filter-row">
            <label>Entité</label>
            <select id="log-entity" class="form-control compact">
                <option value="">Toutes</option>
                <option value="auth">auth</option>
                <option value="users">users</option>
                <option value="clients">clients</option>
                <option value="products">products</option>
                <option value="depots">depots</option>
                <option value="orders">orders</option>
                <option value="stocks">stocks</option>
                <option value="transfers">transfers</option>
                <option value="reports">reports</option>
                <option value="summary">summary</option>
                <option value="audit_logs">audit_logs</option>
                <option value="dashboard">dashboard</option>
                <option value="depots_map">depots_map</option>
            </select>
        </div>
        <div class="filter-row">
            <label>Utilisateur</label>
            <input type="number" id="log-user" class="form-control compact" placeholder="ID utilisateur" />
        </div>
        <div class="filter-row">
            <label>De</label>
            <input type="date" id="log-from" class="form-control compact" />
        </div>
        <div class="filter-row">
            <label>À</label>
            <input type="date" id="log-to" class="form-control compact" />
        </div>
        <div class="filter-row grow">
            <label>Recherche</label>
            <input type="text" id="log-q" class="form-control compact" placeholder="Route, action, entité, nom utilisateur" />
        </div>
        <div class="filter-row">
            <label>Limite</label>
            <input type="number" id="log-limit" class="form-control compact" value="200" min="1" max="1000" />
        </div>
        <div class="filter-row">
            <button id="btn-log-search" class="btn small"><i class="fa fa-search"></i> Rechercher</button>
            <button id="btn-log-reset" class="btn small btn-ghost"><i class="fa fa-eraser"></i> Réinitialiser</button>
            <button id="btn-log-export" class="btn small btn-outline-main"><i class="fa fa-file-export"></i> Export CSV</button>
            <button id="btn-log-export-pdf" class="btn small btn-outline-main"><i class="fa fa-file-pdf"></i> Export PDF</button>
        </div>
    </div>

    <div id="logs-empty" class="muted" style="display:none;padding:.75rem">Aucun résultat</div>
    <div id="logs-grid" class="table-responsive"></div>
    <div class="pager">
        <button class="btn small" id="logs-prev"><i class="fa fa-chevron-left"></i> Précédent</button>
        <span id="logs-page" class="muted">Page 1</span>
        <button class="btn small" id="logs-next">Suivant <i class="fa fa-chevron-right"></i></button>
    </div>
</div>

<script>
    window.ROUTE_BASE = <?= json_encode($routeBase) ?>;
</script>
<script src="<?= $assetBase ?>/assets/js/logs.js"></script>