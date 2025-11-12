<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$assetBase = preg_replace('#/public$#', '', $scriptDir);
?>
<h1>Commandes (Approvisionnements)</h1>
<section class="card">
    <h3>Nouvelle commande</h3>
    <form id="order-form">
        <div class="form-row">
            <label>Référence<input type="text" name="reference" placeholder="AUTO si vide"></label>
            <label>Fournisseur<input type="text" name="supplier"></label>
            <label>Dépôt ID<input type="number" name="depot_id" value="1" min="1"></label>
        </div>
        <div id="order-items">
            <div class="order-item">
                <label>Produit ID<input type="number" name="product_id[]" min="1" required></label>
                <label>Qté<input type="number" name="quantity[]" min="1" required></label>
                <label>Coût unitaire<input type="number" name="unit_cost[]" min="0" required></label>
            </div>
        </div>
        <button type="button" class="btn" id="add-line">Ajouter ligne</button>
        <button class="btn" type="submit">Enregistrer</button>
    </form>
</section>
<section class="card">
    <h3>Historique</h3>
    <table class="excel" id="orders-table">
        <thead>
            <tr>
                <th>Réf</th>
                <th>Fournisseur</th>
                <th>Status</th>
                <th>Total</th>
                <th>Date</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</section>
<script src="<?= $assetBase ?>/assets/js/orders.js"></script>