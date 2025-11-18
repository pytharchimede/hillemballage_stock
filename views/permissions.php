<?php
// Vue gestion des permissions utilisateurs
if (empty($_SESSION['user_id'])) {
    header('Location: ' . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/login');
    exit;
}
include __DIR__ . '/layout/header.php';
?>
<h1>Permissions utilisateurs</h1>
<div class="card">
    <div class="card-body">
        <label for="permUserSelect">Utilisateur:</label>
        <select id="permUserSelect"></select>
        <button id="reloadPerm" class="btn">Recharger</button>
    </div>
</div>
<div id="permMatrix" class="card" style="margin-top:1rem;">
    <div class="card-body">
        <h2>Matrice</h2>
        <table id="permTable" class="table table-sm">
            <thead>
                <tr>
                    <th>Entité</th>
                    <th>Action</th>
                    <th>Autorisé</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
        <button id="savePerm" class="btn btn-primary">Sauvegarder</button>
        <span id="permStatus" style="margin-left:10px;font-weight:bold;"></span>
    </div>
</div>
<script src="<?= preg_replace('#/public$#', '', rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/')) ?>/assets/js/permissions.js"></script>
<?php include __DIR__ . '/layout/footer.php'; ?>