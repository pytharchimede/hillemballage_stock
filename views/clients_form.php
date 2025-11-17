<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$assetBase = preg_replace('#/public$#', '', $scriptDir);
$routeBase = $scriptDir;
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
?>
<h1 id="client-form-title"><?= $id ? 'Modifier le client' : 'Nouveau client' ?></h1>
<section class="card">
    <form id="client-form" class="form-modern" enctype="multipart/form-data" data-id="<?= $id ?>">
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
                <label for="c_address">Adresse</label>
                <div id="addr-combo" class="select-combo">
                    <input id="c_address" class="form-control" type="text" name="address" placeholder="Adresse, quartier, ville…" autocomplete="off" />
                    <div class="combo-menu" style="display:none"></div>
                </div>
            </div>
        </div>

        <div class="form-row">
            <div class="form-field">
                <label for="c_photo">Photo</label>
                <div id="photo-dropzone" class="dropzone" tabindex="0">
                    <div class="dz-label">Glissez-déposez une photo ici ou cliquez pour sélectionner</div>
                    <input id="c_photo" type="file" name="photo" accept="image/*" style="display:none" />
                    <img id="photo-preview" alt="Preview" style="display:none; max-width:180px; border-radius:8px" />
                </div>
                <div style="margin-top:.5rem"><button type="button" id="btn-pick-image" class="btn-ghost"><i class="fa fa-image"></i> Choisir une image</button></div>
            </div>
            <div class="form-field">
                <label>Localisation</label>
                <div style="display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; margin-bottom:.5rem">
                    <button type="button" id="btn-use-location" class="btn-ghost"><i class="fa fa-location-crosshairs"></i> Ma position</button>
                    <span class="muted">Cliquez sur la carte pour définir le point</span>
                </div>
                <div id="client-map" style="width:100%; height: 300px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,.08);"></div>
                <input type="hidden" id="lat" name="latitude" />
                <input type="hidden" id="lng" name="longitude" />
            </div>
        </div>

        <div class="form-actions">
            <button class="btn" type="submit"><i class="fa fa-check"></i> Enregistrer</button>
            <a class="btn secondary" href="<?= $routeBase ?>/clients"><i class="fa fa-list"></i> Retour à la liste</a>
        </div>
        <div id="client-form-msg" class="mt-2"></div>
    </form>
</section>
<script>
    window.ROUTE_BASE = "<?= $routeBase ?>";
    window.CLIENT_ID = <?= $id ?>;
</script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css" crossorigin="anonymous" />
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js" crossorigin="anonymous"></script>
<script src="<?= $assetBase ?>/assets/js/clients_form.js"></script>