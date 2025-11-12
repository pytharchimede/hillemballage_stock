<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$assetBase = preg_replace('#/public$#', '', $scriptDir);
?>
<h1>Dépôts</h1>
<section class="card">
    <h3>Nouveau dépôt</h3>
    <form id="depot-form">
        <div class="form-row">
            <label>Nom<input type="text" name="name" required></label>
            <label>Code<input type="text" name="code" required></label>
        </div>
        <button class="btn" type="submit">Créer</button>
    </form>
</section>
<section class="card">
    <h3>Liste</h3>
    <table class="excel" id="depots-table">
        <thead>
            <tr>
                <th>Nom</th>
                <th>Code</th>
                <th>Latitude</th>
                <th>Longitude</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</section>
<script src="<?= $assetBase ?>/assets/js/depots.js"></script>