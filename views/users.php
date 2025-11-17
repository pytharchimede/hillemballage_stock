<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$assetBase = preg_replace('#/public$#', '', $scriptDir);
?>
<h1>Utilisateurs</h1>
<section class="card">
    <h3>Droits & Groupes</h3>
    <p class="muted">Rôles disponibles et leurs permissions par défaut :</p>
    <ul>
        <li><strong>Admin</strong> : accès total (utilisateurs, produits, dépôts, stocks, ventes, rapports).</li>
        <li><strong>Gérant</strong> : gestion de son dépôt (stocks, transferts, commandes, ventes locales).</li>
        <li><strong>Livreur</strong> : ventes/encaissements, clients et tournée.</li>
    </ul>
    <div style="display:flex; gap:.5rem; align-items:center; margin-top:.5rem">
        <label for="role-filter" class="muted">Filtrer par rôle</label>
        <select id="role-filter">
            <option value="">Tous</option>
            <option value="admin">Admin</option>
            <option value="gerant">Gérant</option>
            <option value="livreur">Livreur</option>
        </select>
    </div>
    <small class="muted">Astuce : vous pouvez assigner un dépôt au gérant pour le lier à un site.</small>

</section>
<section class="card">
    <h3>Nouvel utilisateur</h3>
    <form id="user-form">
        <div class="form-row">
            <label>Nom<input type="text" name="name" required></label>
            <label>Email<input type="email" name="email" required></label>
            <label>Rôle
                <select name="role">
                    <option value="admin">Admin</option>
                    <option value="gerant">Gérant</option>
                    <option value="livreur">Livreur</option>
                </select>
            </label>
            <label>Dépôt ID<input type="number" name="depot_id" min="1" value="1"></label>
            <label>Mot de passe<input type="password" name="password" required></label>
        </div>
        <button class="btn" type="submit">Créer</button>
    </form>
</section>
<section class="card">
    <h3>Liste</h3>
    <table class="excel" id="users-table">
        <thead>
            <tr>
                <th>Nom</th>
                <th>Email</th>
                <th>Rôle</th>
                <th>Dépôt</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</section>
<script>
    window.ROUTE_BASE = "<?= $scriptDir ?>";
</script>
<script src="<?= $assetBase ?>/assets/js/users.js"></script>