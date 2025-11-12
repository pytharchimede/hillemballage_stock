<!doctype html>
<html lang="fr">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Connexion - Hillemballage</title>
    <?php
    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    $assetBase = preg_replace('#/public$#', '', $scriptDir);
    ?>
    <link rel="stylesheet" href="<?= $assetBase ?>/assets/css/app.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>

<body class="login-page">
    <div class="login-card">
        <h1 style="display:flex;align-items:center;gap:.5rem"><i class="fas fa-boxes"></i> Connexion</h1>
        <form method="post" action="<?= $scriptDir ?>/login">
            <input type="hidden" name="_token" value="<?= htmlspecialchars(\App\Support\Security::csrfToken(), ENT_QUOTES) ?>">
            <label>Email
                <input type="email" name="email" placeholder="vous@exemple.com" required>
            </label>
            <label>Mot de passe
                <input type="password" name="password" placeholder="••••••••" required>
            </label>
            <button type="submit" class="btn" style="width:100%">Se connecter</button>
        </form>
    </div>
</body>

</html>