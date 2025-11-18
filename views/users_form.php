<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$assetBase = preg_replace('#/public$#', '', $scriptDir);
$routeBase = $scriptDir;
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
?>
<h1 id="user-form-title"><?= $id ? 'Modifier l\'utilisateur' : 'Nouvel utilisateur' ?></h1>
<section class="card">
    <form id="user-form" class="form-modern" enctype="multipart/form-data" data-id="<?= $id ?>">
        <div class="form-row">
            <div class="form-field" style="flex:1 1 260px;min-width:260px">
                <label>Photo</label>
                <div id="user-photo-dropzone" class="dropzone" tabindex="0">
                    <div class="dz-instructions">
                        Glissez-déposez une image ici ou cliquez pour choisir
                    </div>
                    <img id="user-photo-preview" class="preview" alt="Prévisualisation" style="display:none;max-height:160px;object-fit:cover;border-radius:8px" />
                </div>
                <input id="u_photo" type="file" name="photo" accept="image/*" style="display:none" />
            </div>
        </div>
        <div class="form-row">
            <div class="form-field">
                <label for="u_name">Nom</label>
                <input id="u_name" class="form-control" type="text" name="name" required placeholder="Ex: KONE Ibrahim" />
            </div>
            <div class="form-field">
                <label for="u_email">Email</label>
                <input id="u_email" class="form-control" type="email" name="email" required placeholder="exemple@domaine.com" />
            </div>
            <div class="form-field">
                <label for="u_role">Rôle</label>
                <select id="u_role" class="form-control" name="role">
                    <option value="admin">Admin</option>
                    <option value="gerant">Gérant</option>
                    <option value="livreur">Livreur</option>
                </select>
                <button type="button" id="apply_role_defaults" class="btn secondary" style="margin-top:.5rem">Appliquer les droits du rôle</button>
            </div>
            <div class="form-field">
                <label for="u_depot">Dépôt</label>
                <select id="u_depot" class="form-control" name="depot_id"></select>
            </div>
            <div class="form-field" id="pw-field">
                <label for="u_password">Mot de passe</label>
                <input id="u_password" class="form-control" type="password" name="password" <?= $id ? '' : 'required' ?> />
            </div>
        </div>

        <h3 style="margin:1rem 0 .5rem">Permissions fines</h3>
        <div class="muted" style="margin-bottom:.5rem">Cochez les droits par entité. Si aucune case n\'est cochée, l\'accès est refusé.</div>
        <div class="perm-grid">
            <?php
            $entities = [
                'clients' => 'Clients',
                'products' => 'Produits',
                'depots' => 'Dépôts',
                'stocks' => 'Stocks',
                'transfers' => 'Transferts',
                'orders' => 'Commandes',
                'sales' => 'Ventes',
                'users' => 'Utilisateurs',
                'reports' => 'Rapports',
            ];
            foreach ($entities as $key => $label): ?>
                <div class="perm-row" data-entity="<?= $key ?>">
                    <div class="perm-entity"><?= $label ?></div>
                    <label class="perm-action"><input type="checkbox" class="perm" data-entity="<?= $key ?>" data-action="view"> Voir</label>
                    <label class="perm-action"><input type="checkbox" class="perm" data-entity="<?= $key ?>" data-action="edit"> Modifier</label>
                    <label class="perm-action"><input type="checkbox" class="perm" data-entity="<?= $key ?>" data-action="delete"> Supprimer</label>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="form-actions">
            <button class="btn" type="submit"><i class="fa fa-check"></i> Enregistrer</button>
            <a class="btn secondary" href="<?= $routeBase ?>/users"><i class="fa fa-list"></i> Retour à la liste</a>
        </div>
        <div id="user-form-msg" class="mt-2"></div>
    </form>
</section>
<style>
    .perm-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: .5rem;
    }

    .perm-row {
        display: flex;
        gap: 1rem;
        align-items: center;
        flex-wrap: wrap;
        padding: .5rem .75rem;
        border: 1px solid rgba(0, 0, 0, .06);
        border-radius: 8px;
    }

    .perm-entity {
        flex: 1 1 200px;
        font-weight: 600;
    }

    .perm-action {
        display: flex;
        align-items: center;
        gap: .35rem;
    }
</style>
<script>
    window.ROUTE_BASE = "<?= $routeBase ?>";
    window.USER_ID = <?= $id ?>;
</script>
<script src="<?= $assetBase ?>/assets/js/users_form.js"></script>