<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$routeBase = $scriptDir;
$assetBase = preg_replace('#/public$#', '', $scriptDir);
$id = (int)($_GET['id'] ?? 0);
?>
<h1><i class="fas fa-pen"></i> Modifier dépôt</h1>
<section class="card">
    <form id="depot-form" data-mode="edit" data-depot-id="<?= $id ?>">
        <div class="form-row">
            <label>Nom
                <input type="text" name="name" id="dep-name" required>
            </label>
            <label>Code
                <input type="text" name="code" id="dep-code" required readonly>
            </label>
        </div>
        <div class="form-row">
            <label>Gérant principal
                <input type="text" name="manager_name" id="dep-manager" autocomplete="off" placeholder="Nom du gérant">
                <input type="hidden" name="manager_user_id" id="dep-manager-id">
                <div id="manager-suggest" class="combo-menu" style="display:none; max-height:220px; overflow:auto;"></div>
            </label>
            <label>Téléphone
                <input type="tel" name="phone" id="dep-phone" placeholder="ex: +2250700000000">
            </label>
        </div>
        <div class="form-row">
            <label><input type="checkbox" id="dep-main" disabled> Dépôt principal <span class="muted">(à modifier depuis la liste des dépôts)</span></label>
        </div>
        <div class="form-row">
            <label>Adresse (autocomplète)
                <input type="text" name="address" id="dep-address" autocomplete="off" placeholder="Saisir une adresse">
            </label>
        </div>
        <div id="addr-suggest" class="combo-menu" style="display:none; max-height: 220px; overflow:auto;"></div>
        <input type="hidden" name="latitude" id="dep-lat">
        <input type="hidden" name="longitude" id="dep-lng">
        <div style="margin:12px 0">
            <div id="map" style="width:100%; height: 45vh; border-radius: 10px;"></div>
        </div>
        <div style="display:flex; gap:8px; justify-content:flex-end">
            <a class="btn" href="<?= $routeBase ?>/depots">Annuler</a>
            <button class="btn" type="submit">Enregistrer</button>
        </div>
    </form>
</section>
<script>
    window.ROUTE_BASE = "<?= $routeBase ?>";
</script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css" crossorigin="anonymous" />
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js" crossorigin="anonymous"></script>
<script src="<?= $assetBase ?>/assets/js/depot_form.js"></script>