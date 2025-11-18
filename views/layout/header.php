<!doctype html>
<html lang="fr">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hillemballage</title>
    <?php
    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    $routeBase = $scriptDir; // ex: /hill_new/public
    $assetBase = preg_replace('#/public$#', '', $scriptDir); // ex: /hill_new
    ?>
    <link rel="stylesheet" href="<?= $assetBase ?>/assets/css/app.css">
    <meta name="app-base" content="<?= htmlspecialchars($routeBase) ?>">
    <style>
        /* Masquer les liens sensibles avant calcul des permissions (évite le flash) */
        #mainNav a[data-entity] {
            display: none;
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>

<body>
    <header class="topbar">
        <div class="brand"><i class="fas fa-boxes"></i> Hillemballage</div>
        <button class="hamburger" id="navToggle" aria-label="menu">☰</button>
        <nav id="mainNav">
            <a data-entity="dashboard" data-action="view" href="<?= $routeBase ?>/">Dashboard</a>
            <a data-entity="depots" data-action="view" href="<?= $routeBase ?>/depots">Dépôts</a>
            <a data-entity="depots" data-action="view" href="<?= $routeBase ?>/depots/map">Carte dépôts</a>
            <a data-entity="products" data-action="view" href="<?= $routeBase ?>/products">Produits</a>
            <a data-entity="clients" data-action="view" href="<?= $routeBase ?>/clients">Clients</a>
            <a data-entity="orders" data-action="view" href="<?= $routeBase ?>/orders">Commandes</a>
            <a data-entity="transfers" data-action="view" href="<?= $routeBase ?>/transferts">Transferts</a>
            <a data-entity="sales" data-action="view" href="<?= $routeBase ?>/sales-quick">Vente rapide</a>
            <a data-entity="sales" data-action="view" href="<?= $routeBase ?>/sales">Ventes</a>
            <a data-entity="finance_stock" data-action="view" href="<?= $routeBase ?>/finance-stock">Point financier & stock</a>
            <a data-entity="seller_rounds" data-action="view" href="<?= $routeBase ?>/seller-rounds">Remises</a>
            <a data-entity="collections" data-action="view" href="<?= $routeBase ?>/collections">Recouvrement</a>
            <a data-entity="users" data-action="view" href="<?= $routeBase ?>/users">Utilisateurs</a>
            <a data-entity="audit" data-action="view" href="<?= $routeBase ?>/logs">Logs</a>
            <a data-entity="permissions" data-action="view" href="<?= $routeBase ?>/permissions">Permissions</a>
            <div class="nav-right">
                <?php if (!empty($_SESSION['user_id'])): ?>
                    <span class="user">👤 <?= htmlspecialchars($_SESSION['user_name'] ?? 'Utilisateur') ?></span>
                    <a href="<?= $routeBase ?>/logout" class="btn logout">Déconnexion</a>
                <?php else: ?>
                    <a href="<?= $routeBase ?>/login">Connexion</a>
                <?php endif; ?>
            </div>
        </nav>
    </header>
    <!-- APP_BASE rendu via meta[name=app-base] pour éviter les scripts inline -->
    <div class="toast-container" aria-live="polite" aria-atomic="true"></div>
    <script src="<?= $assetBase ?>/assets/js/ui.js"></script>
    <script src="<?= $assetBase ?>/assets/js/nav.js"></script>
    <main class="container">