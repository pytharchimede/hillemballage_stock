<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$assetBase = preg_replace('#/public$#', '', $scriptDir);
?>
<h1 style="display:flex;align-items:center;justify-content:space-between;gap:1rem">
    <span style="display:flex;align-items:center;gap:.75rem">Utilisateurs <button type="button" id="btn-show-reset-log" class="btn secondary" style="font-size:.75rem"><i class="fa fa-history"></i> Journal des resets</button></span>
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
<div id="pw-reset-modal" class="modal" style="display:none;position:fixed;inset:0;z-index:400;align-items:center;justify-content:center;background:rgba(0,0,0,.45)">
    <div class="modal-content" style="background:#fff;border-radius:12px;max-width:420px;width:100%;padding:1.25rem;box-shadow:0 6px 24px rgba(0,0,0,.25);">
        <h3 style="margin-top:0;display:flex;align-items:center;gap:.5rem"><i class="fa fa-key"></i> Nouveau mot de passe</h3>
        <p style="font-size:.9rem" class="muted">Copiez ce mot de passe et transmettez-le de manière sécurisée. Il ne sera plus affiché après fermeture.</p>
        <div style="display:flex;gap:.5rem;align-items:center;margin:.75rem 0">
            <input id="pw-reset-value" type="text" readonly class="form-control" style="flex:1;font-weight:bold" />
            <button id="pw-reset-copy" class="btn" type="button"><i class="fa fa-copy"></i></button>
        </div>
        <div id="pw-reset-mask" class="muted" style="font-size:.75rem"></div>
        <div style="display:flex;justify-content:flex-end;gap:.5rem;margin-top:1rem">
            <button id="pw-reset-close" class="btn secondary" type="button"><i class="fa fa-times"></i> Fermer</button>
        </div>
    </div>
</div>
<div id="pw-log-modal" class="modal" style="display:none;position:fixed;inset:0;z-index:401;align-items:center;justify-content:center;background:rgba(0,0,0,.45)">
    <div class="modal-content" style="background:#fff;border-radius:12px;max-width:680px;width:100%;padding:1.25rem;box-shadow:0 6px 24px rgba(0,0,0,.25);">
        <h3 style="margin-top:0;display:flex;align-items:center;gap:.5rem"><i class="fa fa-history"></i> Journal des réinitialisations</h3>
        <div id="pw-log-body" style="max-height:360px;overflow:auto;border:1px solid #eee;border-radius:8px"></div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:.75rem">
            <small class="muted">Dernières opérations (limite 200). Les mots de passe affichés sont masqués.</small>
            <button id="pw-log-close" class="btn secondary" type="button"><i class="fa fa-times"></i> Fermer</button>
        </div>
    </div>
</div>
<style>
    .pw-log-table {
        width: 100%;
        border-collapse: collapse;
        font-size: .75rem;
    }

    .pw-log-table th {
        background: #f5f5f5;
        position: sticky;
        top: 0;
        font-weight: 600;
    }

    .pw-log-table th,
    .pw-log-table td {
        padding: .4rem .55rem;
        border-bottom: 1px solid #eee;
        text-align: left;
    }

    .pw-log-table tr:hover {
        background: #fafafa;
    }
</style>
<script>
    window.SHOW_PW_LOG = function() {
        const m = document.getElementById('pw-log-modal');
        if (m) m.style.display = 'flex';
        window.LOAD_PW_LOG && window.LOAD_PW_LOG();
    };
</script>
<script>
    window.ROUTE_BASE = "<?= $scriptDir ?>";
</script>
<script src="<?= $assetBase ?>/assets/js/users.js"></script>