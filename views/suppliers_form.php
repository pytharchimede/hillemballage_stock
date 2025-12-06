<?php
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$routeBase = $scriptDir;
?>
<h1 style="margin:0 0 1rem">Nouveau fournisseur</h1>
<form id="supplier-form" data-route-base="<?= $routeBase ?>" class="card" style="padding:1rem;display:grid;grid-template-columns:repeat(2,1fr);gap:.75rem">
    <input type="text" name="name" placeholder="Nom de l’entreprise" required>
    <input type="text" name="legal_name" placeholder="Raison sociale">
    <input type="text" name="legal_form" placeholder="Forme juridique">
    <input type="text" name="registration_number" placeholder="N° d’enregistrement (RCCM/SIREN)">
    <input type="text" name="tax_id" placeholder="NIF/IFU">
    <input type="email" name="email" placeholder="Email">
    <input type="text" name="phone" placeholder="Téléphone">
    <input type="url" name="website" placeholder="Site web">
    <input type="text" name="address" placeholder="Adresse">
    <input type="text" name="city" placeholder="Ville">
    <input type="text" name="region" placeholder="Région">
    <input type="text" name="country" placeholder="Pays">
    <input type="text" name="postal_code" placeholder="Code postal">
    <input type="text" name="contact_person" placeholder="Personne de contact">
    <input type="text" name="payment_terms" placeholder="Conditions de paiement">
    <input type="text" name="delivery_time" placeholder="Délai moyen de livraison">
    <input type="text" name="bank_name" placeholder="Banque">
    <input type="text" name="iban" placeholder="IBAN/RIB">
    <input type="text" name="bic_swift" placeholder="SWIFT/BIC">
    <!-- Catégories: select search + badges -->
    <div style="grid-column:1 / span 2">
        <label style="display:block;margin-bottom:.25rem;color:#374151">Catégories</label>
        <div id="category-select" class="card" style="padding:.5rem;display:flex;flex-wrap:wrap;gap:.25rem"></div>
        <input type="text" id="category-search" placeholder="Rechercher une catégorie" style="width:100%;margin-top:.5rem">
        <div id="category-suggestions" class="card" style="margin-top:.25rem;max-height:160px;overflow:auto;display:none"></div>
        <input type="hidden" name="categories_json" id="categories-json">
    </div>
    <!-- Localisation: champ simple sans carte -->
    <div style="grid-column:1 / span 2">
        <label style="display:block;margin-bottom:.25rem;color:#374151">Localisation</label>
        <input type="text" id="geo-search" name="location" placeholder="Adresse ou lieu" style="width:100%;margin-bottom:.5rem">
    </div>

    <!-- Documents: Drag & Drop -->
    <div style="grid-column:1 / span 2">
        <label style="display:block;margin-bottom:.25rem;color:#374151">Documents</label>
        <div id="doc-dropzone" class="card" style="padding:1rem;text-align:center;cursor:pointer">Déposez vos fichiers ici ou cliquez pour sélectionner</div>
        <input type="file" id="doc-input" multiple style="display:none">
        <div id="doc-list" style="margin-top:.5rem;display:flex;flex-direction:column;gap:.5rem"></div>
        <input type="hidden" name="documents_json" id="documents-json">
    </div>
    <div style="grid-column:1 / span 2;display:flex;justify-content:flex-end;margin-top:.75rem">
        <button type="submit" class="btn primary">Valider</button>
    </div>
    <!-- Script inline supprimé: tout le JS est dans assets/js/suppliers_form.js -->
</form>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="<?= $routeBase ?>/assets/js/suppliers_form.js"></script>
<!-- Tout le comportement est géré par assets/js/suppliers_form.js pour respecter la CSP -->