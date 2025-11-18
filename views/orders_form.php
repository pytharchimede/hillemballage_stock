<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$assetBase = preg_replace('#/public$#', '', $scriptDir);
?>
<h1>Nouvelle commande (Approvisionnement)</h1>
<section class="card">
    <form id="order-form" class="form-modern">
        <div class="form-row">
            <div class="form-field" style="min-width:220px">
                <label for="o_reference">Référence</label>
                <input id="o_reference" class="form-control" type="text" readonly />
            </div>
            <div class="form-field" style="min-width:260px">
                <label for="o_supplier">Fournisseur</label>
                <input id="o_supplier" class="form-control" type="text" placeholder="Nom du fournisseur" />
            </div>
            <div class="form-field" style="min-width:200px">
                <label for="o_status">Statut</label>
                <select id="o_status" class="form-control">
                    <option value="draft">Brouillon</option>
                    <option value="ordered">Commandé</option>
                    <option value="received">Reçu</option>
                </select>
            </div>
            <div class="form-field" style="min-width:200px">
                <label for="o_depot">Dépôt réception</label>
                <select id="o_depot" class="form-control"></select>
            </div>
        </div>

        <div class="form-row" style="align-items:flex-end">
            <div class="form-field" style="min-width:180px">
                <label for="o_target">Cible stock (par produit)</label>
                <input id="o_target" class="form-control" type="number" min="0" value="10" />
            </div>
            <div class="form-field">
                <button id="btn-propose" class="btn" type="button"><i class="fa fa-magic"></i> Auto-générer</button>
            </div>
            <div class="form-field" style="flex:1;min-width:280px">
                <label for="prod-search">Rechercher/ajouter un produit</label>
                <input id="prod-search" class="form-control" type="text" placeholder="Tapez nom ou SKU…" />
                <div id="prod-search-menu" class="combo-menu" style="display:none;max-height:260px;overflow:auto"></div>
            </div>
        </div>

        <div class="table-responsive" style="margin-top:1rem">
            <table class="excel" id="items-table">
                <thead>
                    <tr>
                        <th>Produit</th>
                        <th style="width:110px">En stock</th>
                        <th style="width:130px">Qté commandée</th>
                        <th style="width:130px">Qté après</th>
                        <th style="width:120px">PU</th>
                        <th style="width:140px">Sous-total</th>
                        <th style="width:60px"></th>
                    </tr>
                </thead>
                <tbody></tbody>
                <tfoot>
                    <tr>
                        <td colspan="5" style="text-align:right;font-weight:600">Total</td>
                        <td id="order-total">0</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="form-actions">
            <button class="btn" type="submit"><i class="fa fa-check"></i> Enregistrer</button>
            <a class="btn secondary" href="<?= $scriptDir ?>/orders"><i class="fa fa-list"></i> Retour à la liste</a>
        </div>
        <div id="order-msg" class="mt-2"></div>
    </form>
</section>

<script>
    window.ROUTE_BASE = "<?= $scriptDir ?>";
    window.ASSET_BASE = "<?= $assetBase ?>";
</script>
<script src="<?= $assetBase ?>/assets/js/orders_form.js"></script>