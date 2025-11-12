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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>

<body>
    <header class="topbar">
        <div class="brand"><i class="fas fa-boxes"></i> Hillemballage</div>
        <button class="hamburger" id="navToggle" aria-label="menu">☰</button>
        <nav id="mainNav">
            <a href="<?= $routeBase ?>/">Dashboard</a>
            <a href="<?= $routeBase ?>/depots/map">Carte dépôts</a>
            <?php if (!empty($_SESSION['user_id'])): ?>
                <span class="user">👤 <?= htmlspecialchars($_SESSION['user_name'] ?? 'Utilisateur') ?></span>
                <a href="<?= $routeBase ?>/logout" class="btn logout">Déconnexion</a>
            <?php else: ?>
                <a href="<?= $routeBase ?>/login">Connexion</a>
            <?php endif; ?>
        </nav>
    </header>
    <script>
        (function() {
            var t = document.getElementById('navToggle');
            if (!t) return;
            var n = document.getElementById('mainNav');
            t.addEventListener('click', function() {
                n.classList.toggle('open');
            });
        })();
        (function syncToken() {
            try {
                var name = 'api_token';
                var parts = ('; ' + document.cookie).split('; ' + name + '=');
                if (parts.length === 2) {
                    var token = parts.pop().split(';').shift();
                    if (token && !localStorage.getItem('api_token')) {
                        localStorage.setItem('api_token', token);
                    }
                }
            } catch (e) {}
        })();
    </script>
    <main class="container">