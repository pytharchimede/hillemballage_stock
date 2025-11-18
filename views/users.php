<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$assetBase = preg_replace('#/public$#', '', $scriptDir);
?>
<h1 style="display:flex;align-items:center;justify-content:space-between;gap:1rem">
    <span>Utilisateurs</span>
    <a class="btn" href="<?= $scriptDir ?>/users/new"><i class="fa fa-user-plus"></i> Nouvel utilisateur</a>
</h1>
<section class="card">
    <h3>Filtres</h3>
    <div class="form-row" style="align-items:flex-end">
        <div class="form-field" style="min-width:240px">
            <label for="q-filter">Recherche</label>
            <input id="q-filter" class="form-control" type="text" placeholder="Nom ou email…" />
        </div>
        <div class="form-field" style="min-width:200px">
            <label for="role-filter">Rôle</label>
            <select id="role-filter" class="form-control">
                <option value="">Tous</option>
                <option value="admin">Admin</option>
                <option value="gerant">Gérant</option>
                <option value="livreur">Livreur</option>
            </select>
        </div>
        <div class="form-field" style="min-width:160px">
            <label for="active-filter">Statut</label>
            <select id="active-filter" class="form-control">
                <option value="">Actifs & inactifs</option>
                <option value="1">Actifs</option>
                <option value="0">Inactifs</option>
            </select>
        </div>
        <div class="form-field" style="min-width:150px">
            <label for="photo-filter">Photo</label>
            <select id="photo-filter" class="form-control">
                <option value="">Tous</option>
                <option value="1">Avec photo</option>
                <option value="0">Sans photo</option>
            </select>
        </div>
        <div class="form-field" style="min-width:220px">
            <label for="depot-filter">Dépôt</label>
            <select id="depot-filter" class="form-control"></select>
        </div>
        <div class="form-field">
            <button id="btn-reset-filters" class="btn secondary" type="button"><i class="fa fa-rotate"></i> Réinitialiser</button>
        </div>
    </div>
    <small class="muted">Astuce : filtrez par dépôt et par rôle, ou tapez un nom/email.</small>
</section>
<section class="card">
    <h3>Liste des utilisateurs</h3>
    <div id="users-grid" class="cards-grid"></div>
    <div id="users-empty" class="muted" style="display:none;padding:.75rem">Aucun utilisateur trouvé.</div>
    <table style="display:none" class="excel" id="users-table">
        <tbody></tbody>
    </table>
</section>
<script>
    window.ROUTE_BASE = "<?= $scriptDir ?>";
</script>
<script src="<?= $assetBase ?>/assets/js/users.js"></script>