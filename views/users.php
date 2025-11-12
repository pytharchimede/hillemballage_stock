<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$assetBase = preg_replace('#/public$#', '', $scriptDir);
?>
<h1>Utilisateurs</h1>
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
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</section>
<script src="<?= $assetBase ?>/assets/js/users.js"></script>