<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$assetBase = preg_replace('#/public$#', '', $scriptDir);
?>
<h1>Clients</h1>
<section class="card">
    <h3>Nouveau client</h3>
    <form id="client-form" enctype="multipart/form-data">
        <div class="form-row">
            <label>Nom<input type="text" name="name" required></label>
            <label>Téléphone<input type="text" name="phone"></label>
            <label>Photo<input type="file" name="photo" accept="image/*"></label>
        </div>
        <button class="btn" type="submit">Créer</button>
    </form>
</section>
<section class="card">
    <h3>Liste</h3>
    <table class="excel" id="clients-table">
        <thead>
            <tr>
                <th>Photo</th>
                <th>Nom</th>
                <th>Téléphone</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</section>
<script src="<?= $assetBase ?>/assets/js/clients.js"></script>