<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$assetBase = preg_replace('#/public$#', '', $scriptDir);
$routeBase = $scriptDir;
?>
<h1>Clients</h1>
<section class="card">
    <h3>Nouveau client</h3>
    <form id="client-form" class="form-modern" enctype="multipart/form-data">
        <div class="form-row">
            <div class="form-field">
                <label for="c_name">Nom</label>
                <input id="c_name" class="form-control" type="text" name="name" required placeholder="Ex: KONE Aïcha" />
            </div>
            <div class="form-field">
                <label for="c_phone">Téléphone</label>
                <input id="c_phone" class="form-control" type="text" name="phone" placeholder="Ex: +2250700000000" />
            </div>
            <div class="form-field">
                <label for="c_photo">Photo</label>
                <input id="c_photo" class="form-control" type="file" name="photo" accept="image/*" />
            </div>
        </div>
        <div class="form-actions">
            <button class="btn" type="submit"><i class="fa fa-user-plus"></i> Créer</button>
        </div>
    </form>
    <div id="client-msg" class="mt-2"></div>

    <hr style="margin:1rem 0; border:none; border-top:1px solid #eee" />
    <h3>Clients</h3>
    <div id="clients-grid" class="cards-grid"></div>
    <div id="clients-empty" class="muted" style="display:none;padding:.75rem">Aucun client trouvé.</div>

    <!-- Ancien tableau masqué (compat) -->
    <table style="display:none" class="excel" id="clients-table">
        <tbody></tbody>
    </table>
</section>
<script>
    window.ROUTE_BASE = "<?= $routeBase ?>";
</script>
<script src="<?= $assetBase ?>/assets/js/clients.js"></script>