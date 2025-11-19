<?php
require __DIR__ . '/../vendor/autoload.php';

use App\Support\DB;
use App\Support\Stock;
use App\Support\Security;
use App\Services\ReportService;
use App\Models\User;
use App\Models\Client;
use App\Models\StockMovement;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Product;

session_start();

function format_fcfa($v): string
{
    $n = (int)$v;
    return number_format($n, 0, ',', ' ') . ' FCFA';
}

function apiUser(): ?array
{
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = null;
    if (preg_match('/Bearer\s+(.*)/i', $hdr, $m)) {
        $token = trim($m[1]);
    }
    if (!$token && isset($_GET['api_token'])) $token = $_GET['api_token'];
    // Fallback session: si pas de token mais session web active, retourner l'utilisateur de la session
    if (!$token && !empty($_SESSION['user_id'])) {
        $uid = (int)$_SESSION['user_id'];
        $row = DB::query('SELECT * FROM users WHERE id=:id LIMIT 1', [':id' => $uid])[0] ?? null;
        if ($row) return $row;
        return null;
    }
    if (!$token) return null;
    $uModel = new User();
    $user = $uModel->findByToken($token);
    if ($user) return $user;
    // Fallback: support token stocké directement dans users.api_token (jeux de données importés)
    try {
        $row = DB::query('SELECT * FROM users WHERE api_token = :t LIMIT 1', [':t' => $token])[0] ?? null;
        if ($row) return $row;
    } catch (\Throwable $e) {
        // ignore
    }
    return null;
}

function parsePermissions(array $u): array
{
    // Admin: tout permis
    if (($u['role'] ?? '') === 'admin') {
        return ['*' => ['view' => true, 'edit' => true, 'delete' => true]];
    }
    $perms = [];
    if (!empty($u['permissions'])) {
        try {
            $decoded = json_decode((string)$u['permissions'], true);
            $perms = is_array($decoded) ? $decoded : [];
        } catch (\Throwable $e) {
            $perms = [];
        }
    }
    // Défauts par rôle si non spécifiés
    $role = $u['role'] ?? '';
    $defaults = [
        'gerant' => [
            'clients' => ['view' => true, 'edit' => true, 'delete' => false],
            'products' => ['view' => true, 'edit' => false, 'delete' => false],
            'depots' => ['view' => true, 'edit' => false, 'delete' => false],
            'stocks' => ['view' => true, 'edit' => true, 'delete' => false],
            'transfers' => ['view' => true, 'edit' => true, 'delete' => false],
            'orders' => ['view' => true, 'edit' => true, 'delete' => false],
            'sales' => ['view' => true, 'edit' => true, 'delete' => false],
            'users' => ['view' => false, 'edit' => false, 'delete' => false],
            'reports' => ['view' => true, 'edit' => false, 'delete' => false],
        ],
        'livreur' => [
            'clients' => ['view' => true, 'edit' => true, 'delete' => false],
            'products' => ['view' => true, 'edit' => false, 'delete' => false],
            'depots' => ['view' => false, 'edit' => false, 'delete' => false],
            'stocks' => ['view' => false, 'edit' => false, 'delete' => false],
            'transfers' => ['view' => false, 'edit' => false, 'delete' => false],
            'orders' => ['view' => false, 'edit' => false, 'delete' => false],
            'sales' => ['view' => true, 'edit' => true, 'delete' => false],
            'users' => ['view' => false, 'edit' => false, 'delete' => false],
            'reports' => ['view' => false, 'edit' => false, 'delete' => false],
            'seller_rounds' => ['view' => false, 'edit' => false, 'delete' => false],
        ],
    ];
    // Merge defaults en normalisant les structures malformées
    if (isset($defaults[$role])) {
        foreach ($defaults[$role] as $ent => $acts) {
            if (!isset($perms[$ent]) || !is_array($perms[$ent])) {
                $perms[$ent] = $acts;
            } else {
                $perms[$ent] = array_merge($acts, $perms[$ent]);
            }
        }
    }
    return $perms;
}

function can(array $u, string $entity, string $action): bool
{
    if (($u['role'] ?? '') === 'admin') return true;
    $perms = parsePermissions($u);
    if (isset($perms['*']) && is_array($perms['*']) && !empty($perms['*'][$action])) return true;
    return (isset($perms[$entity]) && is_array($perms[$entity]) && !empty($perms[$entity][$action]));
}

function requirePermission(array $u, string $entity, string $action)
{
    if (!can($u, $entity, $action)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden', 'entity' => $entity, 'action' => $action]);
        exit;
    }
}

function requireAuth()
{
    $u = apiUser();
    if (!$u) {
        http_response_code(401);
        echo json_encode(['error' => 'Auth required']);
        exit;
    }
    return $u;
}

function selfAdjustStock(int $depotId, int $productId, string $type, int $qty): void
{
    // Create row if not exists
    DB::execute('INSERT IGNORE INTO stocks (depot_id, product_id, quantity, created_at) VALUES (:d,:p,0,NOW())', [':d' => $depotId, ':p' => $productId]);
    $op = in_array($type, ['in', 'return']) ? '+' : '-';
    DB::execute("UPDATE stocks SET quantity = GREATEST(0, quantity $op :q), updated_at = NOW() WHERE depot_id = :d AND product_id = :p", [':q' => $qty, ':d' => $depotId, ':p' => $productId]);
}

function requireRole(array $user, array $roles)
{
    if (!in_array($user['role'] ?? '', $roles, true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
}

// --- Permissions persistence (per-user granular) ---
// Robust creation of user_permissions table with fallback (without FK if engine mismatch)
function ensure_permissions_table(): void
{
    try {
        $t = DB::query('SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "user_permissions"');
        if ($t) return; // already exists
        try {
            DB::execute('CREATE TABLE user_permissions (
                user_id INT NOT NULL,
                entity VARCHAR(64) NOT NULL,
                action VARCHAR(16) NOT NULL,
                allowed TINYINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (user_id, entity, action),
                CONSTRAINT fk_user_permissions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        } catch (\Throwable $e) {
            // Fallback sans contrainte si FK/engine échoue
            DB::execute('CREATE TABLE user_permissions (
                user_id INT NOT NULL,
                entity VARCHAR(64) NOT NULL,
                action VARCHAR(16) NOT NULL,
                allowed TINYINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (user_id, entity, action)
            )');
        }
    } catch (\Throwable $e) { /* ignore */
    }
}
ensure_permissions_table();

// Assurer la colonne depot_id sur clients pour le scoping par dépôt
function ensure_clients_depot_column(): void
{
    try {
        $col = DB::query('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="clients" AND COLUMN_NAME="depot_id"');
        if (!$col) {
            DB::execute('ALTER TABLE clients ADD COLUMN depot_id INT UNSIGNED NULL AFTER address');
            // Index léger
            try {
                DB::execute('CREATE INDEX clients_depot_fk ON clients(depot_id)');
            } catch (\Throwable $e) { /* ignore */
            }
        }
    } catch (\Throwable $e) { /* ignore */
    }
}

// Assurer la colonne credit_limit sur clients (plafond de crédit)
function ensure_clients_credit_limit_column(): void
{
    try {
        $col = DB::query('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="clients" AND COLUMN_NAME="credit_limit"');
        if (!$col) {
            DB::execute('ALTER TABLE clients ADD COLUMN credit_limit INT NOT NULL DEFAULT 0 AFTER longitude');
        }
    } catch (\Throwable $e) { /* ignore */
    }
}

function loadExplicitPermissions(int $uid): array
{
    try {
        $rows = DB::query('SELECT entity, action, allowed FROM user_permissions WHERE user_id=:u', [':u' => $uid]);
        $m = [];
        foreach ($rows as $r) {
            if (!isset($m[$r['entity']])) $m[$r['entity']] = [];
            $m[$r['entity']][$r['action']] = (bool)$r['allowed'];
        }
        return $m;
    } catch (\Throwable $e) {
        return [];
    }
}

function mergeRoleDefaults(string $role, array $explicit): array
{
    if ($role === 'admin') return ['*' => ['view' => true, 'edit' => true, 'delete' => true]];
    $defaults = [
        'gerant' => [
            'dashboard' => ['view' => true, 'export' => true],
            'finance_stock' => ['view' => true, 'export' => true],
            'stocks' => ['view' => true, 'edit' => true],
            'transfers' => ['view' => true, 'edit' => true],
            'orders' => ['view' => true, 'edit' => true],
            'products' => ['view' => true],
            'clients' => ['view' => true, 'edit' => true],
            'collections' => ['view' => true],
            'seller_rounds' => ['view' => true, 'edit' => true],
            'reports' => ['view' => true, 'export' => true],
            'audit' => ['view' => true],
            'permissions' => ['view' => false, 'edit' => false]
        ],
        'livreur' => [
            'dashboard' => ['view' => true],
            'finance_stock' => ['view' => true],
            'sales' => ['view' => true, 'edit' => true],
            'clients' => ['view' => true, 'edit' => true],
            'seller_rounds' => ['view' => false, 'edit' => false],
            'products' => ['view' => true],
        ],
    ];
    $base = $defaults[$role] ?? [];
    // Merge explicit overriding defaults
    foreach ($explicit as $ent => $acts) {
        if (!isset($base[$ent])) $base[$ent] = [];
        foreach ($acts as $a => $ok) {
            $base[$ent][$a] = (bool)$ok;
        }
    }
    return $base;
}

function userEffectivePermissions(array $u): array
{
    if (($u['role'] ?? '') === 'admin') return ['*' => ['view' => true, 'edit' => true, 'delete' => true, 'export' => true]];
    $explicit = loadExplicitPermissions((int)($u['id'] ?? 0));
    return mergeRoleDefaults((string)($u['role'] ?? ''), $explicit);
}

function userCan(array $u, string $entity, string $action): bool
{
    if (($u['role'] ?? '') === 'admin') return true;
    $perms = userEffectivePermissions($u);
    if (isset($perms['*'][$action]) && $perms['*'][$action]) return true;
    return !empty($perms[$entity][$action]);
}

function requireUserCan(array $u, string $entity, string $action): void
{
    if (!userCan($u, $entity, $action)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden', 'entity' => $entity, 'action' => $action]);
        exit;
    }
}

function save_upload(string $field, string $subdir = 'uploads'): ?string
{
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    $name = $_FILES[$field]['name'];
    $tmp = $_FILES[$field]['tmp_name'];
    $ext = pathinfo($name, PATHINFO_EXTENSION);
    $safeExt = preg_replace('/[^a-zA-Z0-9]/', '', $ext);
    $filename = uniqid('f_', true) . ($safeExt ? ('.' . strtolower($safeExt)) : '');
    $dir = __DIR__ . DIRECTORY_SEPARATOR . $subdir;
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $dest = $dir . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($tmp, $dest)) {
        return null;
    }
    // Web path (respect sous-dossier p.ex. /hill_new/public)
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    $web = '/' . trim($subdir, '/') . '/' . $filename;
    return ($base && $base !== '/' ? $base : '') . $web;
}

// --- Audit logging helpers ---
function ensure_audit_table(): void
{
    try {
        DB::execute('CREATE TABLE IF NOT EXISTS audit_logs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            actor_user_id INT UNSIGNED NULL,
            action VARCHAR(32) NOT NULL,
            entity VARCHAR(64) NULL,
            entity_id INT NULL,
            route VARCHAR(255) NOT NULL,
            method VARCHAR(8) NOT NULL,
            ip VARCHAR(64) NULL,
            user_agent VARCHAR(255) NULL,
            meta TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY al_user(actor_user_id),
            KEY al_action(action),
            KEY al_entity(entity),
            KEY al_created(created_at)
        ) ENGINE=InnoDB');
    } catch (\Throwable $e) {
        // ignore to not break main flow
    }
}

function audit_log(?int $actorUserId, string $action, ?string $entity, $entityId, string $route, string $method, array $meta = []): void
{
    ensure_audit_table();
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $metaJson = $meta ? json_encode($meta) : null;
    try {
        DB::execute(
            'INSERT INTO audit_logs(actor_user_id,action,entity,entity_id,route,method,ip,user_agent,meta,created_at) VALUES(:u,:a,:e,:eid,:r,:m,:ip,:ua,:meta,NOW())',
            [
                ':u' => $actorUserId,
                ':a' => $action,
                ':e' => $entity,
                ':eid' => $entityId,
                ':r' => $route,
                ':m' => $method,
                ':ip' => $ip,
                ':ua' => $ua,
                ':meta' => $metaJson,
            ]
        );
    } catch (\Throwable $e) {
        // swallow
    }
}

// --- Seller rounds & payments helpers ---
function ensure_seller_rounds_tables(): void
{
    try {
        DB::execute('CREATE TABLE IF NOT EXISTS seller_rounds (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            depot_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            status ENUM("open","closed") NOT NULL DEFAULT "open",
            cash_turned_in INT NOT NULL DEFAULT 0,
            notes TEXT NULL,
            assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            closed_at DATETIME NULL,
            KEY sr_depot(depot_id),
            KEY sr_user(user_id),
            KEY sr_status(status)
        ) ENGINE=InnoDB');
        DB::execute('CREATE TABLE IF NOT EXISTS seller_round_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            round_id INT UNSIGNED NOT NULL,
            product_id INT UNSIGNED NOT NULL,
            qty_assigned INT NOT NULL DEFAULT 0,
            qty_returned INT NOT NULL DEFAULT 0,
            KEY sri_round(round_id),
            KEY sri_product(product_id)
        ) ENGINE=InnoDB');
    } catch (\Throwable $e) { /* ignore */
    }
}

// Helpers tournées vendeur (seller rounds)
function get_open_round(int $userId, int $depotId): ?array
{
    try {
        $row = DB::query('SELECT * FROM seller_rounds WHERE user_id=:u AND depot_id=:d AND status="open" ORDER BY assigned_at DESC LIMIT 1', [':u' => $userId, ':d' => $depotId])[0] ?? null;
        return $row ?: null;
    } catch (\Throwable $e) {
        return null;
    }
}

function get_round_item(int $roundId, int $productId): array
{
    try {
        $row = DB::query('SELECT qty_assigned, qty_returned FROM seller_round_items WHERE round_id=:r AND product_id=:p LIMIT 1', [':r' => $roundId, ':p' => $productId])[0] ?? null;
        if (!$row) return ['qty_assigned' => 0, 'qty_returned' => 0];
        return ['qty_assigned' => (int)$row['qty_assigned'], 'qty_returned' => (int)$row['qty_returned']];
    } catch (\Throwable $e) {
        return ['qty_assigned' => 0, 'qty_returned' => 0];
    }
}

function get_round_sold_qty(array $round, int $productId): int
{
    $params = [
        ':u' => (int)$round['user_id'],
        ':d' => (int)$round['depot_id'],
        ':p' => $productId,
        ':from' => $round['assigned_at']
    ];
    $whereTo = '';
    if (!empty($round['closed_at'])) {
        $whereTo = ' AND s.sold_at <= :to';
        $params[':to'] = $round['closed_at'];
    }
    try {
        $row = DB::query('SELECT COALESCE(SUM(si.quantity),0) q FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE s.user_id=:u AND s.depot_id=:d AND si.product_id=:p AND s.sold_at >= :from' . $whereTo, $params)[0] ?? ['q' => 0];
        return (int)($row['q'] ?? 0);
    } catch (\Throwable $e) {
        return 0;
    }
}

function ensure_sale_payments_table(): void
{
    try {
        DB::execute('CREATE TABLE IF NOT EXISTS sale_payments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sale_id INT UNSIGNED NOT NULL,
            amount INT NOT NULL,
            method VARCHAR(32) NULL,
            user_id INT UNSIGNED NULL,
            paid_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY sp_sale(sale_id),
            KEY sp_user(user_id),
            KEY sp_paid_at(paid_at)
        ) ENGINE=InnoDB');
    } catch (\Throwable $e) { /* ignore */
    }
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// Normaliser le chemin quand l'appli est servie dans un sous-dossier (/hill_new/public/)
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/'); // ex: /hill_new/public
if ($base && $base !== '/' && str_starts_with($path, $base)) {
    $path = substr($path, strlen($base));
    if ($path === '' || $path === false) {
        $path = '/';
    }
}

// Web auth guard: redirect any non-API route to login if not authenticated
if (!str_starts_with($path, '/api/') && $path !== '/login') {
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/login');
        exit;
    }
}

// Simple router (web + api v1)
if (str_starts_with($path, '/api/v1')) {
    header('Content-Type: application/json');
    // Audit: tracer toute requête API (si possible)
    try {
        $apiUser = apiUser();
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $actionMap = ['GET' => 'view', 'POST' => 'add', 'PATCH' => 'modify', 'PUT' => 'modify', 'DELETE' => 'delete'];
        $mapped = $actionMap[$method] ?? strtolower($method);
        $entity = null;
        if (preg_match('#^/api/v1/([^/]+)#', $path, $mm)) {
            $entity = $mm[1];
        }
        audit_log($apiUser['id'] ?? null, $mapped, $entity, null, $path, $method);
    } catch (\Throwable $e) { /* ignore */
    }
    if ($path === '/api/v1/health') {
        echo json_encode(['status' => 'ok']);
        exit;
    }
    // Auth: récupérer un token depuis la session web (fallback post-login)
    if ($path === '/api/v1/auth/session-token' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        if (empty($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['error' => 'No session']);
            exit;
        }
        $uid = (int)$_SESSION['user_id'];
        $uModel = new User();
        $token = $uModel->createToken($uid);
        setcookie('api_token', $token, time() + 3600 * 24 * 7, '/');
        echo json_encode(['token' => $token]);
        exit;
    }
    // Admin: normaliser les chemins image_path des produits selon le sous-dossier courant
    if ($path === '/api/v1/admin/fix-product-images' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $u = requireAuth();
        requireRole($u, ['admin']);
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        $rows = DB::query('SELECT id, image_path FROM products WHERE image_path IS NOT NULL AND image_path <> ""');
        $normalized = 0;
        foreach ($rows as $row) {
            $img = (string)$row['image_path'];
            // Skip HTTP(S)
            if (preg_match('#^https?://#i', $img)) continue;
            // Already prefixed with base
            if ($base && str_starts_with($img, $base . '/')) continue;
            // Build new path
            if (strlen($img) && $img[0] === '/') {
                $new = ($base && $base !== '/' ? $base : '') . $img;
            } else {
                $new = ($base && $base !== '/' ? $base . '/' : '/') . ltrim($img, '/');
            }
            if ($new !== $img) {
                DB::execute('UPDATE products SET image_path = :p, updated_at = NOW() WHERE id = :id', [':p' => $new, ':id' => (int)$row['id']]);
                $normalized++;
            }
        }
        echo json_encode(['normalized' => $normalized, 'base' => $base]);
        exit;
    }
    // Admin: migrer les clients sans depot_id vers un dépôt par défaut
    if ($path === '/api/v1/admin/clients/migrate-depot' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $u = requireAuth();
        requireRole($u, ['admin']);
        ensure_clients_depot_column();
        ensure_clients_credit_limit_column();
        ensure_clients_credit_limit_column();
        $payload = json_decode(file_get_contents('php://input'), true) ?: $_POST ?: [];
        $targetDepotId = isset($payload['depot_id']) ? (int)$payload['depot_id'] : 0;
        if ($targetDepotId <= 0) {
            // Chercher le dépôt principal sinon le plus ancien
            $main = DB::query('SELECT id FROM depots WHERE is_main = 1 ORDER BY id ASC LIMIT 1')[0]['id'] ?? null;
            if ($main) $targetDepotId = (int)$main;
            if ($targetDepotId <= 0) {
                $any = DB::query('SELECT id FROM depots ORDER BY id ASC LIMIT 1')[0]['id'] ?? null;
                if ($any) $targetDepotId = (int)$any;
            }
        }
        if ($targetDepotId <= 0) {
            http_response_code(422);
            echo json_encode(['error' => 'Aucun dépôt disponible. Créez un dépôt ou fournissez depot_id.']);
            exit;
        }
        $toMigrate = (int)(DB::query('SELECT COUNT(*) c FROM clients WHERE depot_id IS NULL OR depot_id = 0')[0]['c'] ?? 0);
        if ($toMigrate > 0) {
            DB::execute('UPDATE clients SET depot_id = :dep, updated_at = NOW() WHERE depot_id IS NULL OR depot_id = 0', [':dep' => $targetDepotId]);
        }
        $remaining = (int)(DB::query('SELECT COUNT(*) c FROM clients WHERE depot_id IS NULL OR depot_id = 0')[0]['c'] ?? 0);
        echo json_encode(['target_depot_id' => $targetDepotId, 'migrated' => $toMigrate - $remaining, 'remaining' => $remaining]);
        exit;
    }
    // Auth login -> token
    if ($path === '/api/v1/auth/login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        // Assurer colonne initial_quantity (une fois)
        try {
            $col = DB::query('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="order_items" AND COLUMN_NAME="initial_quantity"');
            if (!$col) {
                DB::execute('ALTER TABLE order_items ADD COLUMN initial_quantity INT NOT NULL DEFAULT 0');
            }
        } catch (\Throwable $e) { /* ignorer */
        }
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';
        $uModel = new User();
        $user = $uModel->findByEmail($email);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid credentials']);
            exit;
        }
        $token = $uModel->createToken((int)$user['id']);
        // Audit: connexion
        audit_log((int)$user['id'], 'login', 'auth', (int)$user['id'], $path, 'POST', ['email' => $email]);
        echo json_encode(['token' => $token, 'user' => ['id' => $user['id'], 'name' => $user['name'], 'role' => $user['role']]]);
        exit;
    }
    // Depots listing with geo
    if ($path === '/api/v1/depots' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $auth = requireAuth();
        $role = (string)($auth['role'] ?? '');
        $userDepotId = (int)($auth['depot_id'] ?? 0);
        $q = trim($_GET['q'] ?? '');
        if ($role === 'admin') {
            if ($q !== '') {
                $like = '%' . $q . '%';
                $rows = DB::query('SELECT id,name,code,is_main,manager_user_id,manager_name,phone,address,latitude,longitude FROM depots WHERE name LIKE :q OR code LIKE :q OR manager_name LIKE :q OR address LIKE :q ORDER BY id DESC', [':q' => $like]);
            } else {
                $rows = DB::query('SELECT id,name,code,is_main,manager_user_id,manager_name,phone,address,latitude,longitude FROM depots ORDER BY id DESC');
            }
        } else {
            // Gerant/Livreur: n'accéder qu'à leur dépôt
            if ($userDepotId <= 0) {
                echo json_encode([]);
                exit;
            }
            $rows = DB::query('SELECT id,name,code,is_main,manager_user_id,manager_name,phone,address,latitude,longitude FROM depots WHERE id = :id', [':id' => $userDepotId]);
        }
        echo json_encode($rows);
        exit;
    }
    // Get a single depot
    if (preg_match('#^/api/v1/depots/(\d+)$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $auth = requireAuth();
        $id = (int)$m[1];
        $role = (string)($auth['role'] ?? '');
        $userDepotId = (int)($auth['depot_id'] ?? 0);
        if ($role !== 'admin' && $id !== $userDepotId) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }
        $row = DB::query('SELECT id,name,code,is_main,manager_user_id,manager_name,phone,address,latitude,longitude FROM depots WHERE id=:id', [':id' => $id])[0] ?? null;
        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            exit;
        }
        echo json_encode($row);
        exit;
    }
    // Create depot (admin)
    if ($path === '/api/v1/depots' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $u = requireAuth();
        requireRole($u, ['admin']);
        $data = $_POST ?: (json_decode(file_get_contents('php://input'), true) ?: []);
        // Validation téléphone
        if (!empty($data['phone']) && !preg_match('/^[+0-9\s-]{6,}$/', (string)$data['phone'])) {
            http_response_code(422);
            echo json_encode(['error' => 'Téléphone invalide (ex: +2250700000000)']);
            exit;
        }
        // Validation unicité code
        $code = $data['code'] ?? '';
        if ($code !== '') {
            $exists = DB::query('SELECT id FROM depots WHERE code = :c LIMIT 1', [':c' => $code]);
            if ($exists) {
                http_response_code(409);
                echo json_encode(['error' => 'Code déjà utilisé']);
                exit;
            }
        }
        $managerUserId = isset($data['manager_user_id']) ? (int)$data['manager_user_id'] : null;
        $isMain = !empty($data['is_main']) ? 1 : 0;
        if ($isMain) {
            DB::execute('UPDATE depots SET is_main = 0 WHERE is_main = 1');
        }
        DB::execute('INSERT INTO depots(name,code,is_main,manager_user_id,manager_name,phone,address,latitude,longitude,created_at) VALUES(:n,:c,:im,:mu,:m,:ph,:ad,:lat,:lng,NOW())', [
            ':n' => $data['name'] ?? 'Dépôt',
            ':c' => $code,
            ':im' => $isMain,
            ':mu' => $managerUserId,
            ':m' => $data['manager_name'] ?? null,
            ':ph' => $data['phone'] ?? null,
            ':ad' => $data['address'] ?? null,
            ':lat' => isset($data['latitude']) ? $data['latitude'] : null,
            ':lng' => isset($data['longitude']) ? $data['longitude'] : null,
        ]);
        $id = (int)DB::query('SELECT LAST_INSERT_ID() id')[0]['id'];
        if ($managerUserId) {
            DB::execute('UPDATE users SET role = :r, depot_id = :d, updated_at = NOW() WHERE id = :id', [':r' => 'gerant', ':d' => $id, ':id' => $managerUserId]);
        }
        echo json_encode(['created' => true, 'id' => $id]);
        exit;
    }
    // Update depot info (admin)
    if (preg_match('#^/api/v1/depots/(\d+)$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'PATCH') {
        $u = requireAuth();
        requireRole($u, ['admin']);
        $id = (int)$m[1];
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        // Validation téléphone
        if (!empty($data['phone']) && !preg_match('/^[+0-9\s-]{6,}$/', (string)$data['phone'])) {
            http_response_code(422);
            echo json_encode(['error' => 'Téléphone invalide (ex: +2250700000000)']);
            exit;
        }
        // Validation unicité code si changé
        if (!empty($data['code'])) {
            $exists = DB::query('SELECT id FROM depots WHERE code = :c AND id <> :id LIMIT 1', [':c' => $data['code'], ':id' => $id]);
            if ($exists) {
                http_response_code(409);
                echo json_encode(['error' => 'Code déjà utilisé']);
                exit;
            }
        }
        $isMain = isset($data['is_main']) ? (int)!empty($data['is_main']) : null;
        if ($isMain === 1) {
            DB::execute('UPDATE depots SET is_main = 0 WHERE is_main = 1');
        } elseif ($isMain === 0) {
            // Garde-fou: empêcher qu'il n'y ait plus aucun dépôt principal
            $current = DB::query('SELECT is_main FROM depots WHERE id=:id', [':id' => $id])[0] ?? null;
            if ($current && (int)$current['is_main'] === 1) {
                $others = DB::query('SELECT COUNT(*) c FROM depots WHERE is_main = 1 AND id <> :id', [':id' => $id])[0]['c'] ?? 0;
                if ((int)$others === 0) {
                    http_response_code(422);
                    echo json_encode(['error' => 'Au moins un dépôt principal est requis']);
                    exit;
                }
            }
        }
        $sql = 'UPDATE depots SET name=:n, code=:c, manager_name=:m, phone=:ph, address=:ad, latitude=:lat, longitude=:lng';
        $params = [
            ':n' => $data['name'] ?? 'Depot',
            ':c' => $data['code'] ?? '',
            ':m' => $data['manager_name'] ?? null,
            ':ph' => $data['phone'] ?? null,
            ':ad' => $data['address'] ?? null,
            ':lat' => $data['latitude'] ?? null,
            ':lng' => $data['longitude'] ?? null,
            ':id' => $id
        ];
        if ($isMain !== null) {
            $sql .= ', is_main = :im';
            $params[':im'] = $isMain;
        }
        $sql .= ', updated_at=NOW() WHERE id=:id';
        DB::execute($sql, $params);
        echo json_encode(['updated' => true]);
        exit;
    }
    // Depots geo update
    if (preg_match('#^/api/v1/depots/(\d+)/geo$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'PATCH') {
        $auth = requireAuth();
        requirePermission($auth, 'depots', 'edit');
        $id = (int)$m[1];
        $role = (string)($auth['role'] ?? '');
        $userDepotId = (int)($auth['depot_id'] ?? 0);
        if ($role !== 'admin' && $id !== $userDepotId) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        DB::execute('UPDATE depots SET latitude=:lat, longitude=:lng, updated_at=NOW() WHERE id=:id', [':lat' => $data['latitude'], ':lng' => $data['longitude'], ':id' => $id]);
        echo json_encode(['updated' => true]);
        exit;
    }
    // Clients listing (with balance) - scoped by role/depot
    if ($path === '/api/v1/clients' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $u = requireAuth();
        requirePermission($u, 'clients', 'view');
        ensure_clients_depot_column();
        $role = (string)($u['role'] ?? '');
        $userDepotId = (int)($u['depot_id'] ?? 0);
        $q = trim($_GET['q'] ?? '');
        $depId = isset($_GET['depot_id']) && $_GET['depot_id'] !== '' ? (int)$_GET['depot_id'] : null;
        $where = [];
        $params = [];
        if ($q !== '') {
            $where[] = '(c.name LIKE :q OR c.phone LIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }
        if ($role === 'admin') {
            if ($depId !== null) {
                $where[] = 'c.depot_id = :dep';
                $params[':dep'] = $depId;
            }
        } else {
            // Non-admin: restreindre au dépôt de l'utilisateur
            if ($userDepotId <= 0) {
                echo json_encode([]);
                exit;
            }
            $where[] = 'c.depot_id = :dep';
            $params[':dep'] = $userDepotId;
        }
        $sql = 'SELECT c.id,c.name,c.phone,c.address,c.latitude,c.longitude,c.photo_path,c.created_at, c.depot_id, c.credit_limit,
            (SELECT COALESCE(SUM(s.total_amount) - SUM(s.amount_paid), 0) FROM sales s WHERE s.client_id = c.id) AS balance
            FROM clients c';
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY c.id DESC';
        $rows = DB::query($sql, $params);
        echo json_encode($rows);
        exit;
    }
    // Create client (supports JSON and multipart) - auto-assign depot for non-admin
    if ($path === '/api/v1/clients' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $auth = requireAuth();
        requirePermission($auth, 'clients', 'edit');
        ensure_clients_depot_column();
        ensure_clients_credit_limit_column();
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $data = [];
        if (stripos($contentType, 'application/json') !== false) {
            $data = json_decode(file_get_contents('php://input'), true) ?: [];
        } else {
            $data = $_POST ?: (json_decode(file_get_contents('php://input'), true) ?: []);
        }
        $photo = save_upload('photo');
        $cModel = new Client();
        $role = (string)($auth['role'] ?? '');
        $userDepotId = (int)($auth['depot_id'] ?? 0);
        $targetDepotId = null;
        if ($role === 'admin') {
            $targetDepotId = isset($data['depot_id']) ? (int)$data['depot_id'] : null;
        } else {
            if ($userDepotId <= 0) {
                http_response_code(422);
                echo json_encode(['error' => 'Aucun dépôt associé à l’utilisateur']);
                exit;
            }
            $targetDepotId = $userDepotId;
        }
        $id = $cModel->insert([
            'name' => $data['name'] ?? 'Client',
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'depot_id' => $targetDepotId,
            'latitude' => isset($data['latitude']) ? $data['latitude'] : null,
            'longitude' => isset($data['longitude']) ? $data['longitude'] : null,
            'credit_limit' => isset($data['credit_limit']) && ($role === 'admin' || $role === 'gerant') ? (int)$data['credit_limit'] : 0,
            'photo_path' => $photo,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        echo json_encode(['id' => $id, 'photo_path' => $photo]);
        exit;
    }
    // Update client basic info / photo - scoped by depot for non-admin
    if (preg_match('#^/api/v1/clients/(\d+)$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'PATCH') {
        $auth = requireAuth();
        requirePermission($auth, 'clients', 'edit');
        ensure_clients_depot_column();
        ensure_clients_credit_limit_column();
        $id = (int)$m[1];
        $role = (string)($auth['role'] ?? '');
        $userDepotId = (int)($auth['depot_id'] ?? 0);
        if ($role !== 'admin') {
            $cli = DB::query('SELECT depot_id FROM clients WHERE id=:id', [':id' => $id])[0] ?? null;
            if (!$cli || $userDepotId <= 0 || (int)$cli['depot_id'] !== $userDepotId) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden']);
                exit;
            }
        }
        // Support JSON or multipart (photo)
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            $data = json_decode(file_get_contents('php://input'), true) ?: [];
            $fields = 'name=:n, phone=:p, address=:a';
            $params = [':n' => $data['name'] ?? 'Client', ':p' => $data['phone'] ?? null, ':a' => $data['address'] ?? null, ':id' => $id];
            // Autoriser maj credit_limit pour admin/gerant
            if (in_array(($auth['role'] ?? ''), ['admin', 'gerant'], true) && isset($data['credit_limit'])) {
                $fields .= ', credit_limit=:cl';
                $params[':cl'] = (int)$data['credit_limit'];
            }
            DB::execute('UPDATE clients SET ' . $fields . ', updated_at=NOW() WHERE id=:id', $params);
            echo json_encode(['updated' => true]);
        } else {
            $data = $_POST;
            $photo = save_upload('photo');
            $params = [':n' => $data['name'] ?? 'Client', ':p' => $data['phone'] ?? null, ':a' => $data['address'] ?? null, ':id' => $id];
            $sql = 'UPDATE clients SET name=:n, phone=:p, address=:a';
            if (in_array(($auth['role'] ?? ''), ['admin', 'gerant'], true) && isset($data['credit_limit'])) {
                $sql .= ', credit_limit=:cl';
                $params[':cl'] = (int)$data['credit_limit'];
            }
            if ($photo) {
                $sql .= ', photo_path=:ph';
                $params[':ph'] = $photo;
            }
            $sql .= ', updated_at=NOW() WHERE id=:id';
            DB::execute($sql, $params);
            echo json_encode(['updated' => true, 'photo_path' => $photo]);
        }
        exit;
    }
    // Get single client (with balance & credit_limit) - scoped by depot for non-admin
    if (preg_match('#^/api/v1/clients/(\d+)$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $u = requireAuth();
        requirePermission($u, 'clients', 'view');
        ensure_clients_depot_column();
        ensure_clients_credit_limit_column();
        $role = (string)($u['role'] ?? '');
        $userDepotId = (int)($u['depot_id'] ?? 0);
        $id = (int)$m[1];
        $row = DB::query('SELECT c.id,c.name,c.phone,c.address,c.latitude,c.longitude,c.photo_path,c.created_at,c.depot_id,c.credit_limit,
            (SELECT COALESCE(SUM(s.total_amount) - SUM(s.amount_paid), 0) FROM sales s WHERE s.client_id = c.id) AS balance
            FROM clients c WHERE c.id = :id LIMIT 1', [':id' => $id])[0] ?? null;
        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            exit;
        }
        if ($role !== 'admin') {
            if ($userDepotId <= 0 || (int)$row['depot_id'] !== $userDepotId) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden']);
                exit;
            }
        }
        echo json_encode($row);
        exit;
    }
    // Update client geo - scoped by depot for non-admin
    if (preg_match('#^/api/v1/clients/(\d+)/geo$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'PATCH') {
        $auth = requireAuth();
        requirePermission($auth, 'clients', 'edit');
        ensure_clients_depot_column();
        $role = (string)($auth['role'] ?? '');
        $userDepotId = (int)($auth['depot_id'] ?? 0);
        $id = (int)$m[1];
        if ($role !== 'admin') {
            $cli = DB::query('SELECT depot_id FROM clients WHERE id=:id', [':id' => $id])[0] ?? null;
            if (!$cli || $userDepotId <= 0 || (int)$cli['depot_id'] !== $userDepotId) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden']);
                exit;
            }
        }
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        DB::execute('UPDATE clients SET latitude=:lat, longitude=:lng, updated_at=NOW() WHERE id=:id', [':lat' => $data['latitude'], ':lng' => $data['longitude'], ':id' => $id]);
        echo json_encode(['updated' => true]);
        exit;
    }
    // Stock movement
    if ($path === '/api/v1/stock/movement' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $auth = requireAuth();
        requirePermission($auth, 'stocks', 'edit');
        $role = (string)($auth['role'] ?? '');
        $userDepotId = (int)($auth['depot_id'] ?? 0);
        $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        foreach (['depot_id', 'product_id', 'type', 'quantity'] as $f) if (!isset($data[$f])) {
            http_response_code(422);
            echo json_encode(['error' => "Missing $f"]);
            exit;
        }
        // Restriction de dépôt pour non-admin
        if ($role !== 'admin') {
            if ($role === 'gerant') {
                if ($userDepotId <= 0 || (int)$data['depot_id'] !== $userDepotId) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Forbidden depot']);
                    exit;
                }
            } else {
                // livreur/others: interdit si hors dépôt
                if ($userDepotId <= 0 || (int)$data['depot_id'] !== $userDepotId) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Forbidden depot']);
                    exit;
                }
            }
        }
        $sm = new StockMovement();
        $sm->move((int)$data['depot_id'], (int)$data['product_id'], $data['type'], (int)$data['quantity']);
        selfAdjustStock((int)$data['depot_id'], (int)$data['product_id'], $data['type'], (int)$data['quantity']);
        echo json_encode(['ok' => true]);
        exit;
    }
    // Create sale with items + optional payment
    if ($path === '/api/v1/sales' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $auth = requireAuth();
        $role = (string)($auth['role'] ?? '');
        $userDepotId = (int)($auth['depot_id'] ?? 0);
        $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $items = $data['items'] ?? [];
        if (!$items) {
            http_response_code(422);
            echo json_encode(['error' => 'Items required']);
            exit;
        }
        // Basic validation des lignes
        foreach ($items as $it) {
            $pid = (int)($it['product_id'] ?? 0);
            $qty = (int)($it['quantity'] ?? 0);
            $price = (int)($it['unit_price'] ?? 0);
            if ($pid <= 0 || $qty <= 0) {
                http_response_code(422);
                echo json_encode(['error' => 'INVALID_ITEM', 'details' => ['product_id' => $pid, 'quantity' => $qty]]);
                exit;
            }
            if ($price < 0) {
                http_response_code(422);
                echo json_encode(['error' => 'INVALID_PRICE', 'details' => ['product_id' => $pid]]);
                exit;
            }
        }
        $depotId = (int)$data['depot_id'];
        // Restriction de dépôt pour non-admin
        if ($role !== 'admin') {
            if ($userDepotId <= 0 || $depotId !== $userDepotId) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden depot']);
                exit;
            }
            // Vérifier que le client appartient au même dépôt
            try {
                ensure_clients_depot_column();
                $cli = DB::query('SELECT depot_id FROM clients WHERE id = :id', [':id' => (int)($data['client_id'] ?? 0)])[0] ?? null;
                if (!$cli || (int)$cli['depot_id'] !== $depotId) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Client hors dépôt']);
                    exit;
                }
            } catch (\Throwable $e) {
                http_response_code(422);
                echo json_encode(['error' => 'Client invalide']);
                exit;
            }
            // Vérifier plafond de crédit client (balance actuelle + restant de la vente)
            try {
                $cid = (int)($data['client_id'] ?? 0);
                $cli2 = DB::query('SELECT credit_limit FROM clients WHERE id=:id', [':id' => $cid])[0] ?? null;
                if ($cid > 0 && $cli2) {
                    $rowBal = DB::query('SELECT COALESCE(SUM(total_amount) - SUM(amount_paid),0) b FROM sales WHERE client_id=:c', [':c' => $cid])[0] ?? ['b' => 0];
                    $currentBal = (int)($rowBal['b'] ?? 0);
                    $paymentInit = (int)($data['payment_amount'] ?? 0);
                    $totalTmp = 0;
                    foreach ($items as $it) {
                        $totalTmp += (int)$it['unit_price'] * (int)$it['quantity'];
                    }
                    $newBal = $currentBal + max(0, $totalTmp - $paymentInit);
                    $limit = (int)$cli2['credit_limit'];
                    if ($limit > 0 && $newBal > $limit) {
                        http_response_code(422);
                        echo json_encode(['error' => 'CREDIT_LIMIT_EXCEEDED', 'current' => $currentBal, 'sale_net' => max(0, $totalTmp - $paymentInit), 'limit' => $limit, 'new_balance' => $newBal]);
                        exit;
                    }
                }
            } catch (\Throwable $e) { /* ignore check errors */
            }
            // Livreur: contrôler contre la tournée ouverte et non contre le stock du dépôt
            ensure_seller_rounds_tables();
            $round = get_open_round((int)$auth['id'], $depotId);
            if (!$round) {
                http_response_code(409);
                echo json_encode(['error' => 'Aucune tournée ouverte pour ce livreur (remise requise).']);
                exit;
            }
            foreach ($items as $it) {
                $pid = (int)$it['product_id'];
                $qty = (int)$it['quantity'];
                $ri = get_round_item((int)$round['id'], $pid);
                $sold = get_round_sold_qty($round, $pid);
                $assigned = (int)$ri['qty_assigned'];
                $returned = (int)$ri['qty_returned'];
                $remaining = max(0, $assigned - $returned - $sold);
                if ($qty > $remaining) {
                    http_response_code(422);
                    echo json_encode(['error' => 'INSUFFICIENT_ROUND_STOCK', 'product_id' => $pid, 'requested' => $qty, 'remaining' => $remaining]);
                    exit;
                }
            }
        } else {
            // Admin: Validate stock availability against depot
            foreach ($items as $it) {
                $pid = (int)$it['product_id'];
                $qty = (int)$it['quantity'];
                $available = Stock::available($depotId, $pid);
                if ($available < $qty) {
                    http_response_code(422);
                    echo json_encode(['error' => 'INSUFFICIENT_STOCK', 'product_id' => $pid, 'requested' => $qty, 'available' => $available]);
                    exit;
                }
            }
        }
        $saleModel = new Sale();
        $itemModel = new SaleItem();
        $smModel = new StockMovement();
        $total = 0;
        foreach ($items as $it) {
            $total += ((int)$it['unit_price'] * (int)$it['quantity']);
        }
        $saleId = $saleModel->insert([
            'client_id' => (int)$data['client_id'],
            'user_id' => (int)$auth['id'],
            'depot_id' => $depotId,
            'total_amount' => $total,
            'amount_paid' => 0,
            'status' => 'pending',
            'sold_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s')
        ]);
        foreach ($items as $it) {
            $itemModel->insert([
                'sale_id' => $saleId,
                'product_id' => (int)$it['product_id'],
                'quantity' => (int)$it['quantity'],
                'unit_price' => (int)$it['unit_price'],
                'subtotal' => ((int)$it['unit_price'] * (int)$it['quantity']),
                'created_at' => date('Y-m-d H:i:s')
            ]);
            // Mouvement/ajustement de stock du dépôt uniquement pour admin (ou si non-livreur)
            if ($role === 'admin') {
                $smModel->move((int)$data['depot_id'], (int)$it['product_id'], 'out', (int)$it['quantity'], date('Y-m-d H:i:s'), $saleId, 'sale');
                Stock::adjust($depotId, (int)$it['product_id'], 'out', (int)$it['quantity']);
            }
        }
        if (($data['payment_amount'] ?? 0) > 0) {
            $saleModel->addPayment($saleId, (int)$data['payment_amount']);
        }
        // Mettre à jour le statut payé/dû selon les montants
        $sale = $saleModel->find($saleId);
        if ($sale) {
            $newStatus = ((int)$sale['amount_paid'] >= (int)$sale['total_amount']) ? 'paid' : 'due';
            if (($sale['status'] ?? '') !== $newStatus) {
                DB::execute('UPDATE sales SET status=:st, updated_at=NOW() WHERE id=:id', [':st' => $newStatus, ':id' => $saleId]);
                $sale = $saleModel->find($saleId);
            }
        }

        $sale = $saleModel->find($saleId);
        echo json_encode(['sale' => $sale]);
        exit;
    }
    // Client balance endpoint
    if (preg_match('#^/api/v1/clients/(\d+)/balance$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'GET') {
        requireAuth();
        $cid = (int)$m[1];
        $row = DB::query('SELECT COALESCE(SUM(total_amount),0) total, COALESCE(SUM(amount_paid),0) paid FROM sales WHERE client_id = :c', [':c' => $cid])[0] ?? ['total' => 0, 'paid' => 0];
        $balance = (int)$row['total'] - (int)$row['paid'];
        echo json_encode(['client_id' => $cid, 'total' => (int)$row['total'], 'paid' => (int)$row['paid'], 'balance' => $balance]);
        exit;
    }
    // Client ledger endpoint (debets = ventes, crédits = paiements)
    if (preg_match('#^/api/v1/clients/(\d+)/ledger$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $auth = requireAuth();
        $cid = (int)$m[1];
        // Scope depot for non-admin
        $role = (string)($auth['role'] ?? '');
        $userDepotId = (int)($auth['depot_id'] ?? 0);
        $cli = DB::query('SELECT id,depot_id FROM clients WHERE id=:id', [':id' => $cid])[0] ?? null;
        if (!$cli) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            exit;
        }
        if ($role !== 'admin') {
            if ($userDepotId <= 0 || (int)$cli['depot_id'] !== $userDepotId) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden']);
                exit;
            }
        }
        // Build ledger entries (union sales & payments)
        $entries = DB::query(
            'SELECT t.* FROM (
                SELECT s.id AS ref_id, "sale" AS type, s.sold_at AS dt, s.total_amount AS debit, 0 AS credit, s.user_id, s.depot_id
                FROM sales s WHERE s.client_id = :c
                UNION ALL
                SELECT sp.id AS ref_id, "payment" AS type, sp.paid_at AS dt, 0 AS debit, sp.amount AS credit, sp.user_id, NULL AS depot_id
                FROM sale_payments sp JOIN sales s2 ON s2.id=sp.sale_id WHERE s2.client_id = :c
            ) t ORDER BY t.dt ASC, t.ref_id ASC',
            [':c' => $cid]
        );
        // Compute running balance
        $balance = 0;
        foreach ($entries as &$e) {
            $balance += ((int)$e['debit'] - (int)$e['credit']);
            $e['balance'] = $balance;
        }
        $totals = DB::query('SELECT COALESCE(SUM(total_amount),0) total, COALESCE(SUM(amount_paid),0) paid FROM sales WHERE client_id = :c', [':c' => $cid])[0] ?? ['total' => 0, 'paid' => 0];
        echo json_encode(['client_id' => $cid, 'entries' => $entries, 'total' => (int)$totals['total'], 'paid' => (int)$totals['paid'], 'balance' => (int)$totals['total'] - (int)$totals['paid']]);
        exit;
    }
    // Client ledger export (CSV/PDF)
    if (preg_match('#^/api/v1/clients/(\d+)/ledger/export$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $auth = requireAuth();
        requirePermission($auth, 'clients', 'view');
        $cid = (int)$m[1];
        // Scope depot for non-admin
        $role = (string)($auth['role'] ?? '');
        $userDepotId = (int)($auth['depot_id'] ?? 0);
        $cli = DB::query('SELECT id,name,depot_id FROM clients WHERE id=:id', [':id' => $cid])[0] ?? null;
        if (!$cli) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            exit;
        }
        if ($role !== 'admin') {
            if ($userDepotId <= 0 || (int)$cli['depot_id'] !== $userDepotId) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden']);
                exit;
            }
        }
        $format = strtolower(trim($_GET['format'] ?? 'csv')) === 'pdf' ? 'pdf' : 'csv';
        $entries = DB::query(
            'SELECT t.* FROM (
                SELECT s.id AS ref_id, "sale" AS type, s.sold_at AS dt, s.total_amount AS debit, 0 AS credit, s.user_id, s.depot_id
                FROM sales s WHERE s.client_id = :c
                UNION ALL
                SELECT sp.id AS ref_id, "payment" AS type, sp.paid_at AS dt, 0 AS debit, sp.amount AS credit, sp.user_id, NULL AS depot_id
                FROM sale_payments sp JOIN sales s2 ON s2.id=sp.sale_id WHERE s2.client_id = :c
            ) t ORDER BY t.dt ASC, t.ref_id ASC',
            [':c' => $cid]
        );
        $balance = 0;
        foreach ($entries as &$e) {
            $balance += ((int)$e['debit'] - (int)$e['credit']);
            $e['balance'] = $balance;
        }
        if ($format === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="client_ledger_' + $cid + '.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Client', (string)$cli['name']]);
            fputcsv($out, ['Type', 'Date', 'Débit', 'Crédit', 'Solde']);
            foreach ($entries as $e) fputcsv($out, [$e['type'], (string)$e['dt'], (int)$e['debit'], (int)$e['credit'], (int)$e['balance']]);
            fclose($out);
            exit;
        } else {
            $pdf = new \TCPDF('P', 'mm', 'A4');
            $pdf->SetCreator('Hill Stock');
            $pdf->SetAuthor('Hill Stock');
            $pdf->SetTitle('Relevé client');
            $pdf->AddPage();
            $html = '<h2 style="font-size:16px;margin:0 0 6px">Relevé client</h2>';
            $html .= '<div style="font-size:10px;color:#666">Client: ' . htmlspecialchars((string)$cli['name']) . ' — Généré le ' . htmlspecialchars(date('Y-m-d H:i')) . '</div><br />';
            $html .= '<table border="1" cellpadding="4" cellspacing="0"><thead><tr style="background:#f2f2f2;font-weight:bold">'
                . '<th width="20%">Type</th><th width="30%">Date</th><th width="16%">Débit</th><th width="16%">Crédit</th><th width="18%">Solde</th></tr></thead><tbody>';
            foreach ($entries as $e) {
                $html .= '<tr><td>' . htmlspecialchars((string)$e['type']) . '</td><td>' . htmlspecialchars((string)$e['dt']) . '</td>'
                    . '<td align="right">' . htmlspecialchars(format_fcfa((int)$e['debit'])) . '</td>'
                    . '<td align="right">' . htmlspecialchars(format_fcfa((int)$e['credit'])) . '</td>'
                    . '<td align="right">' . htmlspecialchars(format_fcfa((int)$e['balance'])) . '</td></tr>';
            }
            $html .= '</tbody></table>';
            $pdf->writeHTML($html, true, false, true, false, '');
            $pdf->Output('client_ledger.pdf', 'I');
            exit;
        }
    }
    // Sales listing (scoped by role)
    if ($path === '/api/v1/sales' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $auth = requireAuth();
        $role = (string)($auth['role'] ?? '');
        $uid = (int)($auth['id'] ?? 0);
        $userDepotId = (int)($auth['depot_id'] ?? 0);
        $w = [];
        $p = [];
        if (!empty($_GET['client_id'])) {
            $w[] = 'client_id = :client';
            $p[':client'] = (int)$_GET['client_id'];
        }
        if (!empty($_GET['status'])) {
            $st = $_GET['status'];
            if (in_array($st, ['paid', 'due', 'pending'], true)) {
                $w[] = 'status = :st';
                $p[':st'] = $st;
            }
        }
        // Scope by role
        if ($role === 'admin') {
            if (!empty($_GET['user_id'])) {
                $w[] = 'user_id = :user';
                $p[':user'] = (int)$_GET['user_id'];
            }
            if (!empty($_GET['depot_id'])) {
                $w[] = 'depot_id = :dep';
                $p[':dep'] = (int)$_GET['depot_id'];
            }
        } elseif ($role === 'gerant' && $userDepotId > 0) {
            $w[] = 'depot_id = :dep';
            $p[':dep'] = $userDepotId;
            if (!empty($_GET['user_id'])) {
                $w[] = 'user_id = :user';
                $p[':user'] = (int)$_GET['user_id'];
            }
        } else {
            // livreur ou autre: uniquement ses ventes
            $w[] = 'user_id = :me';
            $p[':me'] = $uid;
        }
        if (!empty($_GET['from'])) {
            $w[] = 'sold_at >= :from';
            $p[':from'] = $_GET['from'] . ' 00:00:00';
        }
        if (!empty($_GET['to'])) {
            $w[] = 'sold_at <= :to';
            $p[':to'] = $_GET['to'] . ' 23:59:59';
        }
        $sql = 'SELECT id, client_id, user_id, depot_id, total_amount, amount_paid, status, sold_at FROM sales';
        if ($w) $sql .= ' WHERE ' . implode(' AND ', $w);
        $sql .= ' ORDER BY sold_at DESC LIMIT 500';
        echo json_encode(DB::query($sql, $p));
        exit;
    }
    // Receivables listing (sales with outstanding balance) with scoping
    if ($path === '/api/v1/receivables' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $auth = requireAuth();
        $role = (string)($auth['role'] ?? '');
        $uid = (int)($auth['id'] ?? 0);
        $userDepotId = (int)($auth['depot_id'] ?? 0);
        $w = ['(total_amount - amount_paid) > 0'];
        $p = [];
        if (!empty($_GET['client_id'])) {
            $w[] = 'client_id = :client';
            $p[':client'] = (int)$_GET['client_id'];
        }
        if (!empty($_GET['from'])) {
            $w[] = 'sold_at >= :from';
            $p[':from'] = $_GET['from'] . ' 00:00:00';
        }
        if (!empty($_GET['to'])) {
            $w[] = 'sold_at <= :to';
            $p[':to'] = $_GET['to'] . ' 23:59:59';
        }
        if ($role === 'admin') {
            if (!empty($_GET['user_id'])) {
                $w[] = 'user_id = :user';
                $p[':user'] = (int)$_GET['user_id'];
            }
            if (!empty($_GET['depot_id'])) {
                $w[] = 'depot_id = :dep';
                $p[':dep'] = (int)$_GET['depot_id'];
            }
        } elseif ($role === 'gerant' && $userDepotId > 0) {
            $w[] = 'depot_id = :dep';
            $p[':dep'] = $userDepotId;
            if (!empty($_GET['user_id'])) {
                $w[] = 'user_id = :user';
                $p[':user'] = (int)$_GET['user_id'];
            }
        } else {
            $w[] = 'user_id = :me';
            $p[':me'] = $uid;
        }
        $sql = 'SELECT id, client_id, user_id, depot_id, total_amount, amount_paid, (total_amount - amount_paid) AS balance, status, sold_at FROM sales';
        if ($w) $sql .= ' WHERE ' . implode(' AND ', $w);
        $sql .= ' ORDER BY sold_at DESC LIMIT 500';
        echo json_encode(DB::query($sql, $p));
        exit;
    }
    // Receivables export (CSV/PDF), aggregated by client in scope
    if ($path === '/api/v1/receivables/export' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $auth = requireAuth();
        // Permission: voir les ventes suffit
        requirePermission($auth, 'sales', 'view');
        $role = (string)($auth['role'] ?? '');
        $uid = (int)($auth['id'] ?? 0);
        $userDepotId = (int)($auth['depot_id'] ?? 0);
        $depotId = isset($_GET['depot_id']) && $_GET['depot_id'] !== '' ? (int)$_GET['depot_id'] : null;
        $userId = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int)$_GET['user_id'] : null;
        $from = trim($_GET['from'] ?? '');
        $to = trim($_GET['to'] ?? '');
        $format = strtolower(trim($_GET['format'] ?? 'csv')) === 'pdf' ? 'pdf' : 'csv';
        // Scope with WHERE; HAVING only for aggregate balance
        $where = [];
        $p = [];
        if ($from !== '') {
            $where[] = 's.sold_at >= :from';
            $p[':from'] = $from . ' 00:00:00';
        }
        if ($to !== '') {
            $where[] = 's.sold_at <= :to';
            $p[':to'] = $to . ' 23:59:59';
        }
        if ($role === 'admin') {
            if ($depotId !== null) {
                $where[] = 'c.depot_id = :dep';
                $p[':dep'] = $depotId;
            }
            if ($userId !== null) {
                $where[] = 's.user_id = :user';
                $p[':user'] = $userId;
            }
        } elseif ($role === 'gerant' && $userDepotId > 0) {
            $where[] = 'c.depot_id = :dep';
            $p[':dep'] = $userDepotId;
            if ($userId !== null) {
                $where[] = 's.user_id = :user';
                $p[':user'] = $userId;
            }
        } else {
            $where[] = 's.user_id = :me';
            $p[':me'] = $uid;
        }
        $sql = 'SELECT c.id AS client_id, c.name AS client_name, c.phone, c.address, c.latitude, c.longitude, c.depot_id, (SUM(s.total_amount) - SUM(s.amount_paid)) AS balance, SUM(s.total_amount) AS total, SUM(s.amount_paid) AS paid, MAX(s.sold_at) AS last_sale
            FROM sales s JOIN clients c ON c.id = s.client_id';
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' GROUP BY c.id,c.name,c.phone,c.address,c.latitude,c.longitude,c.depot_id HAVING (SUM(s.total_amount) - SUM(s.amount_paid)) > 0';
        $rows = DB::query($sql, $p);
        // Attach depot/user labels if needed
        $depotNames = [];
        try {
            $deps = DB::query('SELECT id,name FROM depots');
            foreach ($deps as $d) $depotNames[(int)$d['id']] = $d['name'];
        } catch (\Throwable $e) {
        }
        if ($format === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="receivables.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Client', 'Téléphone', 'Adresse', 'Dépôt', 'Total', 'Payé', 'Solde', 'Dernière vente', 'Latitude', 'Longitude']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    (string)$r['client_name'],
                    (string)($r['phone'] ?? ''),
                    (string)($r['address'] ?? ''),
                    $depotNames[(int)$r['depot_id']] ?? (string)$r['depot_id'],
                    format_fcfa((int)$r['total']),
                    format_fcfa((int)$r['paid']),
                    format_fcfa((int)$r['balance']),
                    (string)$r['last_sale'],
                    (string)($r['latitude'] ?? ''),
                    (string)($r['longitude'] ?? '')
                ]);
            }
            fclose($out);
            exit;
        } else {
            $pdf = new \TCPDF('L', 'mm', 'A4');
            $pdf->SetCreator('Hill Stock');
            $pdf->SetAuthor('Hill Stock');
            $pdf->SetTitle('Créances');
            $pdf->AddPage();
            $html = '<h2 style="font-size:16px;margin:0 0 6px">Créances (par client)</h2>';
            $html .= '<div style="font-size:10px;color:#666">Généré le ' . htmlspecialchars(date('Y-m-d H:i')) . '</div>';
            $html .= '<br />';
            $html .= '<table border="1" cellpadding="4" cellspacing="0"><thead><tr style="background:#f2f2f2;font-weight:bold">'
                . '<th width="40%">Client</th>'
                . '<th width="20%">Dépôt</th>'
                . '<th width="13%">Total</th>'
                . '<th width="13%">Payé</th>'
                . '<th width="14%">Solde</th>'
                . '</tr></thead><tbody>';
            foreach ($rows as $r) {
                $html .= '<tr>'
                    . '<td>' . htmlspecialchars((string)$r['client_name']) . '</td>'
                    . '<td>' . htmlspecialchars($depotNames[(int)$r['depot_id']] ?? (string)$r['depot_id']) . '</td>'
                    . '<td align="right">' . htmlspecialchars(format_fcfa((int)$r['total'])) . '</td>'
                    . '<td align="right">' . htmlspecialchars(format_fcfa((int)$r['paid'])) . '</td>'
                    . '<td align="right">' . htmlspecialchars(format_fcfa((int)$r['balance'])) . '</td>'
                    . '</tr>';
            }
            $html .= '</tbody></table>';
            $pdf->writeHTML($html, true, false, true, false, '');
            $pdf->Output('receivables.pdf', 'I');
            exit;
        }
    }
    // Products listing (optional q search, optional depot-specific stock)
    if ($path === '/api/v1/products' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $auth = requireAuth();
        $role = (string)($auth['role'] ?? '');
        $userDepotId = (int)($auth['depot_id'] ?? 0);
        $q = trim($_GET['q'] ?? '');
        $depId = isset($_GET['depot_id']) && $_GET['depot_id'] !== '' ? (int)$_GET['depot_id'] : null;
        if ($role !== 'admin' && $role !== 'gerant') {
            // Forcer le dépôt à celui de l'utilisateur (livreur/other)
            $depId = $userDepotId ?: null;
        }
        $onlyInStock = isset($_GET['only_in_stock']) && $_GET['only_in_stock'] !== '' ? ($_GET['only_in_stock'] === '1') : false;
        if ($role !== 'admin' && $role !== 'gerant') {
            // Livreur/other: ne renvoyer que les produits disponibles dans son dépôt
            $onlyInStock = true;
        }
        $params = [];
        if ($depId !== null) {
            $params[':dep'] = $depId;
        }
        $stockDepotExpr = ($depId !== null)
            ? '(SELECT COALESCE(SUM(s.quantity),0) FROM stocks s WHERE s.product_id = p.id AND s.depot_id = :dep)'
            : 'NULL';
        if ($q !== '') {
            $like = '%' . $q . '%';
            $params[':q'] = $like;
            $sql = 'SELECT p.id,p.name,p.sku,p.unit_price,p.description,p.image_path,p.active,
                (SELECT COALESCE(SUM(s.quantity),0) FROM stocks s WHERE s.product_id = p.id) AS stock_total,
                ' . $stockDepotExpr . ' AS stock_depot
                FROM products p WHERE (p.name LIKE :q OR p.sku LIKE :q)';
            if ($depId !== null && $onlyInStock) {
                $sql .= ' AND (SELECT COALESCE(SUM(s.quantity),0) FROM stocks s WHERE s.product_id = p.id AND s.depot_id = :dep) > 0';
            }
            $sql .= ' ORDER BY p.id DESC';
            $rows = DB::query($sql, $params);
        } else {
            $sql = 'SELECT p.id,p.name,p.sku,p.unit_price,p.description,p.image_path,p.active,
                (SELECT COALESCE(SUM(s.quantity),0) FROM stocks s WHERE s.product_id = p.id) AS stock_total,
                ' . $stockDepotExpr . ' AS stock_depot
                FROM products p';
            if ($depId !== null && $onlyInStock) {
                $sql .= ' WHERE (SELECT COALESCE(SUM(s.quantity),0) FROM stocks s WHERE s.product_id = p.id AND s.depot_id = :dep) > 0';
            }
            $sql .= ' ORDER BY p.id DESC';
            $rows = DB::query($sql, $params);
        }
        echo json_encode($rows);
        exit;
    }
    // Seller rounds: open a round (admin/gerant)
    if ($path === '/api/v1/seller-rounds/open' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $auth = requireAuth();
        requireRole($auth, ['admin', 'gerant']);
        ensure_seller_rounds_tables();
        $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $userId = (int)($data['user_id'] ?? 0);
        $depotId = (int)($data['depot_id'] ?? 0);
        if ($userId <= 0 || $depotId <= 0) {
            http_response_code(422);
            echo json_encode(['error' => 'user_id et depot_id requis']);
            exit;
        }
        // Gerant ne peut ouvrir que sur son dépôt
        if (($auth['role'] ?? '') === 'gerant' && (int)($auth['depot_id'] ?? 0) !== $depotId) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden depot']);
            exit;
        }
        $existing = get_open_round($userId, $depotId);
        if ($existing) {
            echo json_encode(['round' => $existing, 'already_open' => true]);
            exit;
        }
        DB::execute('INSERT INTO seller_rounds(depot_id,user_id,status,cash_turned_in,assigned_at) VALUES(:d,:u,\'open\',0,NOW())', [':d' => $depotId, ':u' => $userId]);
        $rid = (int)DB::query('SELECT LAST_INSERT_ID() id')[0]['id'];
        echo json_encode(['round' => ['id' => $rid, 'depot_id' => $depotId, 'user_id' => $userId, 'status' => 'open']]);
        exit;
    }
    // Seller rounds: assign products to round (admin/gerant)
    if (preg_match('#^/api/v1/seller-rounds/(\d+)/assign$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $auth = requireAuth();
        requireRole($auth, ['admin', 'gerant']);
        ensure_seller_rounds_tables();
        $rid = (int)$m[1];
        $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $pid = (int)($data['product_id'] ?? 0);
        $qty = (int)($data['quantity'] ?? 0);
        if ($pid <= 0 || $qty <= 0) {
            http_response_code(422);
            echo json_encode(['error' => 'product_id et quantity requis']);
            exit;
        }
        $round = DB::query('SELECT * FROM seller_rounds WHERE id=:id AND status=\'open\' LIMIT 1', [':id' => $rid])[0] ?? null;
        if (!$round) {
            http_response_code(404);
            echo json_encode(['error' => 'Round not found or closed']);
            exit;
        }
        // Gerant limité à son dépôt
        if (($auth['role'] ?? '') === 'gerant' && (int)($auth['depot_id'] ?? 0) !== (int)$round['depot_id']) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden depot']);
            exit;
        }
        // Vérifier stock du dépôt
        $available = Stock::available((int)$round['depot_id'], $pid);
        if ($available < $qty) {
            http_response_code(422);
            echo json_encode(['error' => 'INSUFFICIENT_STOCK', 'available' => $available]);
            exit;
        }
        // Upsert round item
        $exists = DB::query('SELECT qty_assigned FROM seller_round_items WHERE round_id=:r AND product_id=:p', [':r' => $rid, ':p' => $pid])[0] ?? null;
        if ($exists) {
            DB::execute('UPDATE seller_round_items SET qty_assigned = qty_assigned + :q WHERE round_id=:r AND product_id=:p', [':q' => $qty, ':r' => $rid, ':p' => $pid]);
        } else {
            DB::execute('INSERT INTO seller_round_items(round_id,product_id,qty_assigned,qty_returned) VALUES(:r,:p,:q,0)', [':r' => $rid, ':p' => $pid, ':q' => $qty]);
        }
        // Impacter le stock du dépôt à la remise
        (new StockMovement())->move((int)$round['depot_id'], $pid, 'out', $qty, date('Y-m-d H:i:s'), null, 'round_assign');
        Stock::adjust((int)$round['depot_id'], $pid, 'out', $qty);
        echo json_encode(['assigned' => true]);
        exit;
    }
    // Seller rounds: return quantities (admin/gerant)
    if (preg_match('#^/api/v1/seller-rounds/(\d+)/return$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'PATCH') {
        $auth = requireAuth();
        requireRole($auth, ['admin', 'gerant']);
        ensure_seller_rounds_tables();
        $rid = (int)$m[1];
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $items = $data['items'] ?? [];
        $round = DB::query('SELECT * FROM seller_rounds WHERE id=:id AND status=\'open\' LIMIT 1', [':id' => $rid])[0] ?? null;
        if (!$round) {
            http_response_code(404);
            echo json_encode(['error' => 'Round not found or closed']);
            exit;
        }
        if (($auth['role'] ?? '') === 'gerant' && (int)($auth['depot_id'] ?? 0) !== (int)$round['depot_id']) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden depot']);
            exit;
        }
        foreach ($items as $it) {
            $pid = (int)($it['product_id'] ?? 0);
            $qty = (int)($it['quantity'] ?? 0);
            if ($pid <= 0 || $qty < 0) continue;
            $ri = get_round_item($rid, $pid);
            $assigned = (int)$ri['qty_assigned'];
            $sold = get_round_sold_qty($round, $pid);
            $maxReturnable = max(0, $assigned - $sold);
            if ($qty > $maxReturnable) {
                http_response_code(422);
                echo json_encode(['error' => 'RETURN_EXCEEDS_AVAILABLE', 'product_id' => $pid, 'max' => $maxReturnable]);
                exit;
            }
            // Mettre à jour qty_returned au cumul
            $newReturned = (int)$ri['qty_returned'] + $qty;
            if ($newReturned > $assigned) $newReturned = $assigned;
            $exists = DB::query('SELECT 1 FROM seller_round_items WHERE round_id=:r AND product_id=:p', [':r' => $rid, ':p' => $pid]);
            if ($exists) {
                DB::execute('UPDATE seller_round_items SET qty_returned=:qr WHERE round_id=:r AND product_id=:p', [':qr' => $newReturned, ':r' => $rid, ':p' => $pid]);
            } else {
                DB::execute('INSERT INTO seller_round_items(round_id,product_id,qty_assigned,qty_returned) VALUES(:r,:p,0,:qr)', [':r' => $rid, ':p' => $pid, ':qr' => $newReturned]);
            }
            // Retour au dépôt
            (new StockMovement())->move((int)$round['depot_id'], $pid, 'return', $qty, date('Y-m-d H:i:s'), null, 'round_return');
            Stock::adjust((int)$round['depot_id'], $pid, 'in', $qty);
        }
        echo json_encode(['returned' => true]);
        exit;
    }
    // Products export (CSV/PDF) with same scoping and filters
    if ($path === '/api/v1/products/export' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $auth = requireAuth();
        // products:view suffit pour exporter la liste
        requirePermission($auth, 'products', 'view');
        $role = (string)($auth['role'] ?? '');
        $userDepotId = (int)($auth['depot_id'] ?? 0);
        $q = trim($_GET['q'] ?? '');
        $depId = isset($_GET['depot_id']) && $_GET['depot_id'] !== '' ? (int)$_GET['depot_id'] : null;
        if ($role !== 'admin' && $role !== 'gerant') {
            $depId = $userDepotId ?: null;
        }
        $onlyInStock = isset($_GET['only_in_stock']) && $_GET['only_in_stock'] !== '' ? ($_GET['only_in_stock'] === '1') : false;
        if ($role !== 'admin' && $role !== 'gerant') {
            $onlyInStock = true;
        }
        $params = [];
        if ($depId !== null) $params[':dep'] = $depId;
        $stockDepotExpr = ($depId !== null)
            ? '(SELECT COALESCE(SUM(s.quantity),0) FROM stocks s WHERE s.product_id = p.id AND s.depot_id = :dep)'
            : 'NULL';
        if ($q !== '') {
            $params[':q'] = '%' . $q . '%';
            $sql = 'SELECT p.id,p.name,p.sku,p.unit_price,
                (SELECT COALESCE(SUM(s.quantity),0) FROM stocks s WHERE s.product_id = p.id) AS stock_total,
                ' . $stockDepotExpr . ' AS stock_depot
                FROM products p WHERE (p.name LIKE :q OR p.sku LIKE :q)';
            if ($depId !== null && $onlyInStock) {
                $sql .= ' AND (SELECT COALESCE(SUM(s.quantity),0) FROM stocks s WHERE s.product_id = p.id AND s.depot_id = :dep) > 0';
            }
            $sql .= ' ORDER BY p.id DESC';
        } else {
            $sql = 'SELECT p.id,p.name,p.sku,p.unit_price,
                (SELECT COALESCE(SUM(s.quantity),0) FROM stocks s WHERE s.product_id = p.id) AS stock_total,
                ' . $stockDepotExpr . ' AS stock_depot
                FROM products p';
            if ($depId !== null && $onlyInStock) {
                $sql .= ' WHERE (SELECT COALESCE(SUM(s.quantity),0) FROM stocks s WHERE s.product_id = p.id AND s.depot_id = :dep) > 0';
            }
            $sql .= ' ORDER BY p.id DESC';
        }
        $rows = DB::query($sql, $params);
        $format = strtolower(trim($_GET['format'] ?? 'csv'));
        if ($format === 'pdf') {
            // Générer PDF via TCPDF
            $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
            $pdf->SetCreator('Hill');
            $pdf->SetAuthor('Hill');
            $pdf->SetTitle('Produits');
            $pdf->SetMargins(10, 10, 10);
            $pdf->AddPage();
            $html = '<h3>Liste des produits</h3>';
            $html .= '<table border="1" cellpadding="4"><thead><tr><th>ID</th><th>SKU</th><th>Produit</th><th>PU</th><th>Stock</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $stock = ($depId !== null) ? ($r['stock_depot'] ?? 0) : ($r['stock_total'] ?? 0);
                $html .= '<tr>'
                    . '<td>' . (int)$r['id'] . '</td>'
                    . '<td>' . htmlspecialchars($r['sku'] ?? '') . '</td>'
                    . '<td>' . htmlspecialchars($r['name'] ?? '') . '</td>'
                    . '<td align="right">' . htmlspecialchars(format_fcfa((int)$r['unit_price'])) . '</td>'
                    . '<td align="right">' . (int)$stock . '</td>'
                    . '</tr>';
            }
            $html .= '</tbody></table>';
            $pdf->writeHTML($html, true, false, true, false, '');
            $pdf->Output('produits.pdf', 'I');
            exit;
        }
        // CSV par défaut (Excel compatible)
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="produits.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID', 'SKU', 'Produit', 'Prix (FCFA)', 'Stock']);
        foreach ($rows as $r) {
            $stock = ($depId !== null) ? ($r['stock_depot'] ?? 0) : ($r['stock_total'] ?? 0);
            fputcsv($out, [
                (int)$r['id'],
                (string)($r['sku'] ?? ''),
                (string)($r['name'] ?? ''),
                (int)$r['unit_price'],
                (int)$stock,
            ]);
        }
        fclose($out);
        exit;
    }
    // Stocks by depot for a product
    if ($path === '/api/v1/stocks' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $auth = requireAuth();
        $role = (string)($auth['role'] ?? '');
        $userDepotId = (int)($auth['depot_id'] ?? 0);
        $pid = (int)($_GET['product_id'] ?? 0);
        if ($pid <= 0) {
            http_response_code(422);
            echo json_encode(['error' => 'product_id required']);
            exit;
        }
        if ($role === 'admin') {
            $rows = DB::query('SELECT s.depot_id, d.name AS depot_name, d.code AS depot_code, s.quantity FROM stocks s JOIN depots d ON d.id = s.depot_id WHERE s.product_id = :p ORDER BY d.name ASC', [':p' => $pid]);
        } else {
            $rows = DB::query('SELECT s.depot_id, d.name AS depot_name, d.code AS depot_code, s.quantity FROM stocks s JOIN depots d ON d.id = s.depot_id WHERE s.product_id = :p AND s.depot_id = :dep ORDER BY d.name ASC', [':p' => $pid, ':dep' => $userDepotId]);
        }
        echo json_encode($rows);
        exit;
    }
    // Create product (admin) + image upload
    if ($path === '/api/v1/products' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $u = requireAuth();
        requireRole($u, ['admin']);
        $data = $_POST ?: (json_decode(file_get_contents('php://input'), true) ?: []);
        $img = save_upload('image', 'uploads/products');
        // Auto SKU if not provided: 3 letters of name + time base36
        $rawName = trim($data['name'] ?? 'Produit');
        $prefix = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $rawName) ?: 'PRD', 0, 3));
        $autoSku = $prefix . '-' . strtoupper(base_convert((string)time(), 10, 36));
        $sku = ($data['sku'] ?? '') ?: $autoSku;
        DB::execute('INSERT INTO products(name,sku,unit_price,description,image_path,created_at) VALUES(:n,:s,:p,:d,:img,NOW())', [':n' => $rawName, ':s' => $sku, ':p' => (int)($data['unit_price'] ?? 0), ':d' => ($data['description'] ?? null), ':img' => $img]);
        $pid = (int)DB::query('SELECT LAST_INSERT_ID() id')[0]['id'];
        // Optional initial stock entry
        if (!empty($data['initial_quantity'])) {
            $qty = (int)$data['initial_quantity'];
            $dep = (int)($data['depot_id'] ?? 1);
            if ($qty > 0) {
                (new StockMovement())->move($dep, $pid, 'in', $qty, date('Y-m-d H:i:s'), null, 'initial');
                Stock::adjust($dep, $pid, 'in', $qty);
            }
        }
        echo json_encode(['created' => true, 'id' => $pid, 'sku' => $sku, 'image_path' => $img]);
        exit;
    }
    // Get single product
    if (preg_match('#^/api/v1/products/(\d+)$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'GET') {
        requireAuth();
        $id = (int)$m[1];
        $row = DB::query('SELECT id,name,sku,unit_price,description,image_path,active FROM products WHERE id=:id', [':id' => $id])[0] ?? null;
        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            exit;
        }
        echo json_encode($row);
        exit;
    }
    // Update product (admin)
    if (preg_match('#^/api/v1/products/(\d+)$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'PATCH') {
        $u = requireAuth();
        requireRole($u, ['admin']);
        $id = (int)$m[1];
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            $data = json_decode(file_get_contents('php://input'), true) ?: [];
            $sets = ['name=:n', 'sku=:s', 'unit_price=:p', 'description=:d'];
            $params = [
                ':n' => $data['name'] ?? 'Produit',
                ':s' => $data['sku'] ?? '',
                ':p' => (int)($data['unit_price'] ?? 0),
                ':d' => ($data['description'] ?? null),
                ':id' => $id
            ];
            if (isset($data['active'])) {
                $sets[] = 'active=:a';
                $params[':a'] = (int)$data['active'];
            }
            $sql = 'UPDATE products SET ' . implode(', ', $sets) . ', updated_at=NOW() WHERE id=:id';
            DB::execute($sql, $params);
            // Optional stock in during edit
            if (!empty($data['initial_quantity'])) {
                $qty = (int)$data['initial_quantity'];
                $dep = (int)($data['depot_id'] ?? 0);
                if ($qty > 0 && $dep > 0) {
                    (new StockMovement())->move($dep, $id, 'in', $qty, date('Y-m-d H:i:s'), null, 'edit');
                    Stock::adjust($dep, $id, 'in', $qty);
                }
            }
            echo json_encode(['updated' => true]);
        } else {
            $data = $_POST;
            $img = save_upload('image', 'uploads/products');
            $sets = ['name=:n', 'sku=:s', 'unit_price=:p', 'description=:d'];
            $params = [
                ':n' => $data['name'] ?? 'Produit',
                ':s' => $data['sku'] ?? '',
                ':p' => (int)($data['unit_price'] ?? 0),
                ':d' => ($data['description'] ?? null),
                ':id' => $id
            ];
            if ($img) {
                $sets[] = 'image_path=:img';
                $params[':img'] = $img;
            }
            if (isset($data['active'])) {
                $sets[] = 'active=:a';
                $params[':a'] = (int)$data['active'];
            }
            $sql = 'UPDATE products SET ' . implode(', ', $sets) . ', updated_at=NOW() WHERE id=:id';
            DB::execute($sql, $params);
            // Optional stock in during edit (multipart)
            if (!empty($data['initial_quantity'])) {
                $qty = (int)$data['initial_quantity'];
                $dep = (int)($data['depot_id'] ?? 0);
                if ($qty > 0 && $dep > 0) {
                    (new StockMovement())->move($dep, $id, 'in', $qty, date('Y-m-d H:i:s'), null, 'edit');
                    Stock::adjust($dep, $id, 'in', $qty);
                }
            }
            echo json_encode(['updated' => true, 'image_path' => $img]);
        }
        exit;
    }
    // Update product via POST override (multipart form supports) _method=PATCH
    if (preg_match('#^/api/v1/products/(\d+)$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (($_POST['_method'] ?? '') !== 'PATCH') {
            // Not an override; let other handlers (if any) process; else 404 below
        } else {
            $u = requireAuth();
            requireRole($u, ['admin']);
            $id = (int)$m[1];
            $data = $_POST;
            $img = save_upload('image', 'uploads/products');
            $sets = ['name=:n', 'sku=:s', 'unit_price=:p', 'description=:d'];
            $params = [
                ':n' => $data['name'] ?? 'Produit',
                ':s' => $data['sku'] ?? '',
                ':p' => (int)($data['unit_price'] ?? 0),
                ':d' => ($data['description'] ?? null),
                ':id' => $id
            ];
            if ($img) {
                $sets[] = 'image_path=:img';
                $params[':img'] = $img;
            }
            if (isset($data['active'])) {
                $sets[] = 'active=:a';
                $params[':a'] = (int)$data['active'];
            }
            $sql = 'UPDATE products SET ' . implode(', ', $sets) . ', updated_at=NOW() WHERE id=:id';
            DB::execute($sql, $params);
            // Optional stock in during edit (override)
            if (!empty($data['initial_quantity'])) {
                $qty = (int)$data['initial_quantity'];
                $dep = (int)($data['depot_id'] ?? 0);
                if ($qty > 0 && $dep > 0) {
                    (new StockMovement())->move($dep, $id, 'in', $qty, date('Y-m-d H:i:s'), null, 'edit');
                    Stock::adjust($dep, $id, 'in', $qty);
                }
            }
            echo json_encode(['updated' => true, 'image_path' => $img]);
            exit;
        }
    }
    // Users management (admin) + runtime colonne photo_path & active
    if ($path === '/api/v1/users' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $u = requireAuth();
        requirePermission($u, 'users', 'view');
        // Assurer colonnes supplémentaires
        try {
            $cols = DB::query('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="users"');
            $havePhoto = false;
            $haveActive = false;
            foreach ($cols as $c) {
                if ($c['COLUMN_NAME'] === 'photo_path') $havePhoto = true;
                if ($c['COLUMN_NAME'] === 'active') $haveActive = true;
            }
            if (!$havePhoto) {
                DB::execute('ALTER TABLE users ADD COLUMN photo_path VARCHAR(255) NULL AFTER permissions');
            }
            if (!$haveActive) {
                DB::execute('ALTER TABLE users ADD COLUMN active TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER photo_path');
            }
        } catch (\Throwable $e) { /* ignore */
        }
        $role = trim($_GET['role'] ?? '');
        $q = trim($_GET['q'] ?? '');
        $depotId = isset($_GET['depot_id']) && $_GET['depot_id'] !== '' ? (int)$_GET['depot_id'] : null;
        $activeFilter = isset($_GET['active']) && $_GET['active'] !== '' ? ($_GET['active'] === '0' ? 0 : 1) : null;
        $hasPhoto = isset($_GET['has_photo']) && $_GET['has_photo'] !== '' ? ($_GET['has_photo'] === '1' ? 1 : 0) : null;
        $where = [];
        $params = [];
        if ($role !== '') {
            $where[] = 'role = :r';
            $params[':r'] = $role;
        }
        if ($depotId !== null) {
            $where[] = 'depot_id = :d';
            $params[':d'] = $depotId;
        }
        if ($q !== '') {
            $where[] = '(name LIKE :q OR email LIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }
        if ($activeFilter !== null) {
            $where[] = 'active = :ac';
            $params[':ac'] = $activeFilter;
        }
        if ($hasPhoto !== null) {
            if ($hasPhoto === 1) {
                $where[] = '(photo_path IS NOT NULL AND photo_path <> "")';
            } else {
                $where[] = '(photo_path IS NULL OR photo_path = "")';
            }
        }
        $sql = 'SELECT id,name,email,role,depot_id,permissions,photo_path,active,created_at,'
            . '(SELECT created_at FROM user_password_resets upr WHERE upr.user_id=users.id ORDER BY upr.id DESC LIMIT 1) AS last_reset_at '
            . 'FROM users';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id DESC';
        $rows = DB::query($sql, $params);
        echo json_encode($rows);
        exit;
    }
    // Lightweight users list for selection (admin/gerant)
    if ($path === '/api/v1/users/brief' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $u = requireAuth();
        requireRole($u, ['admin', 'gerant']);
        $role = trim($_GET['role'] ?? '');
        $depotId = isset($_GET['depot_id']) && $_GET['depot_id'] !== '' ? (int)$_GET['depot_id'] : null;
        $where = ['active = 1'];
        $params = [];
        if ($role !== '') {
            $where[] = 'role = :r';
            $params[':r'] = $role;
        }
        if ($depotId !== null) {
            $where[] = 'depot_id = :d';
            $params[':d'] = $depotId;
        }
        $sql = 'SELECT id,name,role,depot_id FROM users';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY name ASC, id ASC';
        $rows = DB::query($sql, $params);
        echo json_encode($rows);
        exit;
    }
    // Create user (admin/editor)
    if ($path === '/api/v1/users' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $u = requireAuth();
        requirePermission($u, 'users', 'edit');
        // Ensure columns exist
        try {
            $cols = DB::query('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="users"');
            $havePhoto = false;
            $haveActive = false;
            foreach ($cols as $c) {
                if ($c['COLUMN_NAME'] === 'photo_path') $havePhoto = true;
                if ($c['COLUMN_NAME'] === 'active') $haveActive = true;
            }
            if (!$havePhoto) DB::execute('ALTER TABLE users ADD COLUMN photo_path VARCHAR(255) NULL AFTER permissions');
            if (!$haveActive) DB::execute('ALTER TABLE users ADD COLUMN active TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER photo_path');
        } catch (\Throwable $e) {
        }
        $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        // Validate email
        if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            http_response_code(422);
            echo json_encode(['error' => 'Email invalide']);
            exit;
        }
        // Unique email
        $exists = DB::query('SELECT id FROM users WHERE email=:e LIMIT 1', [':e' => $data['email']]);
        if ($exists) {
            http_response_code(409);
            echo json_encode(['error' => 'Email déjà utilisé']);
            exit;
        }
        $hash = password_hash($data['password'] ?? 'secret123', PASSWORD_BCRYPT);
        $permsJson = isset($data['permissions']) ? json_encode($data['permissions']) : null;
        $photo = null;
        if (!empty($_FILES['photo'])) {
            $up = save_upload('photo', 'uploads');
            if ($up) $photo = $up;
        }
        DB::execute('INSERT INTO users(name,email,password_hash,role,depot_id,permissions,photo_path,active,created_at) VALUES(:n,:e,:h,:r,:d,:p,:ph,1,NOW())', [
            ':n' => $data['name'] ?? 'User',
            ':e' => $data['email'] ?? '',
            ':h' => $hash,
            ':r' => $data['role'] ?? 'gerant',
            ':d' => (int)($data['depot_id'] ?? 1),
            ':p' => $permsJson,
            ':ph' => $photo
        ]);
        $newId = (int)DB::query('SELECT LAST_INSERT_ID() id')[0]['id'];
        echo json_encode(['created' => true, 'id' => $newId, 'photo_path' => $photo]);
        exit;
    }
    if ($path === '/api/v1/summary' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $auth = requireAuth();
        $role = (string)($auth['role'] ?? '');
        $uid = (int)($auth['id'] ?? 0);
        $userDepotId = (int)($auth['depot_id'] ?? 0);
        $days = (int)($_GET['days'] ?? 30);
        if (!in_array($days, [7, 30, 90], true)) $days = 30;
        $paramDepot = isset($_GET['depot_id']) ? (int)$_GET['depot_id'] : null;
        $threshold = (int)($_GET['threshold'] ?? 5);
        if (!in_array($threshold, [3, 5, 10], true)) $threshold = 5;
        // Ensure optional tables for rounds and payments
        try {
            ensure_seller_rounds_tables();
        } catch (\Throwable $e) {
        }
        try {
            ensure_sale_payments_table();
        } catch (\Throwable $e) {
        }
        // Ensure cost_price column exists on products
        try {
            $col = DB::query('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="products" AND COLUMN_NAME="cost_price"');
            if (!$col) {
                DB::execute('ALTER TABLE products ADD COLUMN cost_price INT NOT NULL DEFAULT 0 AFTER unit_price');
            }
        } catch (\Throwable $e) {
        }

        // Scope helpers (sales)
        $salesWhere = [];
        $salesParams = [];
        if ($role === 'admin') {
            // admin may focus a specific depot if provided
            if ($paramDepot && $paramDepot > 0) {
                $salesWhere[] = 'depot_id = :dep';
                $salesParams[':dep'] = $paramDepot;
            }
        } elseif ($role === 'gerant' && $userDepotId > 0) {
            $salesWhere[] = 'depot_id = :dep';
            $salesParams[':dep'] = $userDepotId;
        } else {
            $salesWhere[] = 'user_id = :uid';
            $salesParams[':uid'] = $uid;
        }
        $salesScopeSql = $salesWhere ? (' WHERE ' . implode(' AND ', $salesWhere)) : '';

        // Scope helpers (stocks)
        $stockWhere = [];
        $stockParams = [];
        if ($role === 'admin') {
        } elseif ($role === 'gerant' && $userDepotId > 0) {
            $stockWhere[] = 's.depot_id = :dep';
            $stockParams[':dep'] = $userDepotId;
        } else {
            // pas de contrainte claire pour stocks par utilisateur, laisser vide
        }
        $stockScopeSql = $stockWhere ? (' WHERE ' . implode(' AND ', $stockWhere)) : '';

        // Stock KPIs
        $stockTotal = (int)(DB::query('SELECT COALESCE(SUM(quantity),0) qty FROM stocks s' . ($stockScopeSql ? $stockScopeSql : ''))[0]['qty'] ?? 0);
        $stockValuation = (int)(DB::query('SELECT COALESCE(SUM(s.quantity * p.cost_price),0) v FROM stocks s JOIN products p ON p.id=s.product_id' . ($stockScopeSql ? $stockScopeSql : ''), $stockParams)[0]['v'] ?? 0);
        $stockLines = DB::query('SELECT COALESCE(SUM(s.quantity),0) quantity, p.name FROM products p LEFT JOIN stocks s ON s.product_id=p.id' . ($stockScopeSql ? $stockScopeSql : '') . ' GROUP BY p.id,p.name ORDER BY quantity DESC LIMIT 10', $stockParams);

        // Top soldes clients (créances) dans le scope
        $topBalances = DB::query('SELECT c.id, c.name, (SUM(s.total_amount) - SUM(s.amount_paid)) AS balance FROM sales s JOIN clients c ON c.id = s.client_id' . $salesScopeSql . ' GROUP BY c.id,c.name HAVING balance > 0 ORDER BY balance DESC LIMIT 5', $salesParams);

        // Encours total (receivables)
        $receivablesTotal = (int)(DB::query('SELECT COALESCE(SUM(total_amount - amount_paid),0) v FROM sales' . $salesScopeSql, $salesParams)[0]['v'] ?? 0);

        // Daily detailed (use user depot if available, else 1)
        $today = date('Y-m-d');
        $dailyDepot = $userDepotId > 0 ? $userDepotId : 1;
        $daily = ReportService::daily($dailyDepot, $today);

        // Quick stats (today)
        $sqlBaseToday = ' FROM sales' . ($salesScopeSql ? $salesScopeSql . ' AND' : ' WHERE') . ' DATE(sold_at)=CURDATE()';
        $caToday = (int)(DB::query('SELECT COALESCE(SUM(total_amount),0) v' . $sqlBaseToday, $salesParams)[0]['v'] ?? 0);
        $salesToday = (int)(DB::query('SELECT COUNT(*) c' . $sqlBaseToday, $salesParams)[0]['c'] ?? 0);
        $activeClients30 = (int)(DB::query('SELECT COUNT(DISTINCT client_id) c FROM sales' . ($salesScopeSql ? $salesScopeSql . ' AND' : ' WHERE') . ' sold_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)', $salesParams)[0]['c'] ?? 0);

        // Sparkline last 7 days revenue (scope)
        $sparkRows = DB::query('SELECT DATE(sold_at) d, SUM(total_amount) v FROM sales' . ($salesScopeSql ? $salesScopeSql . ' AND' : ' WHERE') . ' sold_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY DATE(sold_at) ORDER BY d ASC', $salesParams);
        $map7 = [];
        foreach ($sparkRows as $r) {
            $map7[$r['d']] = (int)$r['v'];
        }
        $spark = [];
        for ($i = 6; $i >= 0; $i--) {
            $dt = date('Y-m-d', strtotime("-{$i} day"));
            $spark[] = ['date' => $dt, 'value' => ($map7[$dt] ?? 0)];
        }

        // Revenue last 30 days (scope)
        $rows30 = DB::query('SELECT DATE(sold_at) d, SUM(total_amount) v FROM sales' . ($salesScopeSql ? $salesScopeSql . ' AND' : ' WHERE') . ' sold_at >= DATE_SUB(CURDATE(), INTERVAL ' . ($days - 1) . ' DAY) GROUP BY DATE(sold_at) ORDER BY d ASC', $salesParams);
        $map30 = [];
        foreach ($rows30 as $r) {
            $map30[$r['d']] = (int)$r['v'];
        }
        $series30 = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $dt = date('Y-m-d', strtotime("-{$i} day"));
            $series30[] = ['date' => $dt, 'value' => ($map30[$dt] ?? 0)];
        }

        // Top products by revenue (30d)
        $topProducts = DB::query('SELECT p.name, SUM(si.subtotal) total FROM sale_items si JOIN sales s ON s.id=si.sale_id JOIN products p ON p.id=si.product_id' . ($salesScopeSql ? $salesScopeSql . ' AND' : ' WHERE') . ' s.sold_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY p.id,p.name ORDER BY total DESC LIMIT 10', $salesParams);

        // Low stock products (<= threshold)
        $lowStock = DB::query('SELECT p.id, p.name, COALESCE(SUM(s.quantity),0) qty FROM products p LEFT JOIN stocks s ON s.product_id=p.id' . ($stockScopeSql ? $stockScopeSql : '') . ' GROUP BY p.id,p.name HAVING qty <= :th ORDER BY qty ASC, p.name ASC LIMIT 10', $stockParams + [':th' => $threshold]);

        // Orders status distribution (global or scope-agnostic)
        $ordersStatus = DB::query('SELECT status, COUNT(*) c FROM orders GROUP BY status');

        // Top users by sales (30d, scoped by depot if needed)
        $byUser = DB::query('SELECT u.id, u.name, SUM(s.total_amount) total FROM sales s JOIN users u ON u.id=s.user_id' . ($salesScopeSql ? $salesScopeSql . ' AND' : ' WHERE') . ' s.sold_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY u.id,u.name ORDER BY total DESC LIMIT 5', $salesParams);

        // Latest sales (10)
        $latest = DB::query('SELECT s.id, c.name AS client_name, s.total_amount, s.sold_at, s.depot_id FROM sales s LEFT JOIN clients c ON c.id=s.client_id' . ($salesScopeSql ? $salesScopeSql . ' AND' : ' WHERE') . ' s.sold_at IS NOT NULL ORDER BY s.sold_at DESC LIMIT 10', $salesParams);

        // Operational KPIs: rounds open, cash turned in today, collections today
        // Rounds open (scoped)
        $roundsWhere = ['sr.status = "open"'];
        $roundsParams = [];
        if ($role === 'admin') {
            if ($paramDepot && $paramDepot > 0) {
                $roundsWhere[] = 'sr.depot_id = :dep';
                $roundsParams[':dep'] = $paramDepot;
            }
        } elseif ($role === 'gerant' && $userDepotId > 0) {
            $roundsWhere[] = 'sr.depot_id = :dep';
            $roundsParams[':dep'] = $userDepotId;
        } else {
            $roundsWhere[] = 'sr.user_id = :uid';
            $roundsParams[':uid'] = $uid;
        }
        $roundsSql = 'SELECT COUNT(*) c FROM seller_rounds sr WHERE ' . implode(' AND ', $roundsWhere);
        $roundsOpen = (int)(DB::query($roundsSql, $roundsParams)[0]['c'] ?? 0);

        // Cash turned in today (closed today)
        $cashWhere = ['sr.status = "closed"', 'DATE(sr.closed_at) = CURDATE()'];
        $cashParams = [];
        if ($role === 'admin') {
            if ($paramDepot && $paramDepot > 0) {
                $cashWhere[] = 'sr.depot_id = :dep';
                $cashParams[':dep'] = $paramDepot;
            }
        } elseif ($role === 'gerant' && $userDepotId > 0) {
            $cashWhere[] = 'sr.depot_id = :dep';
            $cashParams[':dep'] = $userDepotId;
        } else {
            $cashWhere[] = 'sr.user_id = :uid';
            $cashParams[':uid'] = $uid;
        }
        $cashSql = 'SELECT COALESCE(SUM(sr.cash_turned_in),0) v FROM seller_rounds sr WHERE ' . implode(' AND ', $cashWhere);
        $cashToday = (int)(DB::query($cashSql, $cashParams)[0]['v'] ?? 0);

        // Collections (recouvrement) today based on sale_payments joined to sales with same scope
        $payWhere = ['DATE(sp.paid_at) = CURDATE()'];
        $payParams = $salesParams; // reuse params names :dep / :uid
        if ($role === 'admin') {
            if ($paramDepot && $paramDepot > 0) {
                $payWhere[] = 's.depot_id = :dep';
            }
        } elseif ($role === 'gerant' && $userDepotId > 0) {
            $payWhere[] = 's.depot_id = :dep';
        } else {
            $payWhere[] = 's.user_id = :uid';
        }
        $collSql = 'SELECT COALESCE(SUM(sp.amount),0) v FROM sale_payments sp JOIN sales s ON s.id = sp.sale_id WHERE ' . implode(' AND ', $payWhere);
        $collectionsToday = (int)(DB::query($collSql, $payParams)[0]['v'] ?? 0);

        // Visibility (coarse-grained)
        $visibility = [
            'finance' => ($role === 'admin' || $role === 'gerant'),
            'clients' => (function () use ($auth) {
                try {
                    return can($auth, 'clients', 'view');
                } catch (\Throwable $e) {
                    return false;
                }
            })(),
            'stocks' => (function () use ($auth) {
                try {
                    return can($auth, 'stocks', 'view');
                } catch (\Throwable $e) {
                    return false;
                }
            })(),
            'orders' => (function () use ($auth) {
                try {
                    return can($auth, 'orders', 'view');
                } catch (\Throwable $e) {
                    return false;
                }
            })(),
            'users' => (function () use ($auth) {
                try {
                    return can($auth, 'users', 'view');
                } catch (\Throwable $e) {
                    return false;
                }
            })(),
            'sales' => (function () use ($auth) {
                try {
                    return can($auth, 'sales', 'view');
                } catch (\Throwable $e) {
                    return false;
                }
            })(),
            'audit' => ($role === 'admin'),
            'role' => $role
        ];

        // Server-side KPI masking based on visibility flags
        if (!$visibility['finance']) {
            $receivablesTotal = null;
            $series30 = [];
            $cashToday = null;
            $collectionsToday = null;
        }
        if (!$visibility['stocks']) {
            $stockTotal = null;
            $stockValuation = null;
            $lowStock = [];
            $stockLines = [];
        }
        if (!$visibility['clients']) {
            $topBalances = [];
        }
        if (!$visibility['users']) {
            $byUser = [];
        }

        echo json_encode([
            'stock_total' => $stockTotal !== null ? (int)$stockTotal : null,
            'stock_items' => $stockLines,
            'stock_valuation' => isset($stockValuation) && $stockValuation !== null ? (int)$stockValuation : null,
            'top_balances' => $topBalances,
            'daily' => $daily,
            'quick_stats' => [
                'ca_today' => $caToday,
                'sales_today' => $salesToday,
                'active_clients' => $activeClients30,
                'receivables_total' => $receivablesTotal,
                'rounds_open' => $roundsOpen,
                'cash_turned_in_today' => $cashToday,
                'collections_today' => $collectionsToday,
                'stock_valuation' => isset($stockValuation) && $stockValuation !== null ? (int)$stockValuation : null,
                'window' => $days . 'd'
            ],
            'sparkline' => $spark,
            'revenue_30d' => $series30,
            'top_products_30d' => $topProducts,
            'low_stock' => $lowStock,
            'orders_status' => $ordersStatus,
            'sales_by_user_30d' => $byUser,
            'latest_sales' => $latest,
            'visibility' => $visibility
        ]);
        exit;
    }
    if (preg_match('#^/api/v1/users/(\d+)$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $u = requireAuth();
        requirePermission($u, 'users', 'view');
        $id = (int)$m[1];
        // Colonnes runtime
        try {
            $cols = DB::query('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="users"');
            $havePhoto = false;
            $haveActive = false;
            foreach ($cols as $c) {
                if ($c['COLUMN_NAME'] === 'photo_path') $havePhoto = true;
                if ($c['COLUMN_NAME'] === 'active') $haveActive = true;
            }
            if (!$havePhoto) {
                DB::execute('ALTER TABLE users ADD COLUMN photo_path VARCHAR(255) NULL AFTER permissions');
            }
            if (!$haveActive) {
                DB::execute('ALTER TABLE users ADD COLUMN active TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER photo_path');
            }
        } catch (\Throwable $e) { /* ignore */
        }
        $row = DB::query('SELECT id,name,email,role,depot_id,permissions,photo_path,active,created_at FROM users WHERE id=:id LIMIT 1', [':id' => $id])[0] ?? null;
        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            exit;
        }
        echo json_encode($row);
        exit;
    }
    if (preg_match('#^/api/v1/users/(\d+)$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'PATCH') {
        $u = requireAuth();
        requirePermission($u, 'users', 'edit');
        $id = (int)$m[1];
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        // Email validation/unicité si changé
        if (isset($data['email'])) {
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                http_response_code(422);
                echo json_encode(['error' => 'Email invalide']);
                exit;
            }
            $dup = DB::query('SELECT id FROM users WHERE email=:e AND id<>:id LIMIT 1', [':e' => $data['email'], ':id' => $id]);
            if ($dup) {
                http_response_code(409);
                echo json_encode(['error' => 'Email déjà utilisé']);
                exit;
            }
        }
        $sets = ['name=:n', 'email=:e', 'role=:r', 'depot_id=:d'];
        $params = [':n' => $data['name'] ?? null, ':e' => $data['email'] ?? null, ':r' => $data['role'] ?? null, ':d' => (int)($data['depot_id'] ?? 0), ':id' => $id];
        if (array_key_exists('permissions', $data)) {
            $sets[] = 'permissions=:p';
            $params[':p'] = $data['permissions'] !== null ? json_encode($data['permissions']) : null;
        }
        if (!empty($data['password'])) {
            $sets[] = 'password_hash=:h';
            $params[':h'] = password_hash($data['password'], PASSWORD_BCRYPT);
        }
        if (array_key_exists('active', $data)) {
            $sets[] = 'active=:ac';
            $params[':ac'] = !empty($data['active']) ? 1 : 0;
        }
        // Photo upload (support multipart PATCH via form-data hors spec fetch JSON) - si fichier présent
        if (!empty($_FILES['photo'])) {
            $up = save_upload('photo', 'uploads');
            if ($up) {
                $sets[] = 'photo_path=:ph';
                $params[':ph'] = $up;
            }
        }
        DB::execute('UPDATE users SET ' . implode(',', $sets) . ', updated_at=NOW() WHERE id=:id', $params);
        echo json_encode(['updated' => true]);
        exit;
    }
    // Secure password reset (admin only) returns temporary password
    if (preg_match('#^/api/v1/users/(\d+)/reset-password$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $u = requireAuth();
        requireRole($u, ['admin']); // seulement admin
        $id = (int)$m[1];
        $row = DB::query('SELECT id FROM users WHERE id=:id LIMIT 1', [':id' => $id])[0] ?? null;
        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            exit;
        }
        try {
            // Génération mot de passe fort (12 caractères)
            $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789@$!%*?&';
            $pw = '';
            for ($i = 0; $i < 12; $i++) {
                $pw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $hash = password_hash($pw, PASSWORD_BCRYPT);

            // Assurer table de log
            DB::execute('CREATE TABLE IF NOT EXISTS user_password_resets (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                admin_id INT UNSIGNED NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                password_mask VARCHAR(60) NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                KEY upr_user_fk(user_id),
                KEY upr_admin_fk(admin_id)
            ) ENGINE=InnoDB');

            // Mettre à jour le mot de passe utilisateur
            DB::execute('UPDATE users SET password_hash=:h, updated_at=NOW() WHERE id=:id', [':h' => $hash, ':id' => $id]);

            // Enregistrer le log avec un mask (ex: ab********YZ)
            $mask = substr($pw, 0, 2) . str_repeat('*', max(0, strlen($pw) - 4)) . substr($pw, -2);
            DB::execute(
                'INSERT INTO user_password_resets(user_id,admin_id,password_hash,password_mask,created_at) VALUES(:u,:a,:ph,:pm,NOW())',
                [':u' => $id, ':a' => (int)$u['id'], ':ph' => $hash, ':pm' => $mask]
            );
            $logId = (int)(DB::query('SELECT LAST_INSERT_ID() id')[0]['id'] ?? 0);

            echo json_encode(['reset' => true, 'password' => $pw, 'log_id' => $logId, 'mask' => $mask]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'RESET_FAILED', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // Listing des logs de réinitialisation (admin)
    if ($path === '/api/v1/users/reset-logs' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $u = requireAuth();
        requireRole($u, ['admin']);
        $userId = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int)$_GET['user_id'] : null;
        // Assurer table pour éviter erreurs en prod
        try {
            DB::execute('CREATE TABLE IF NOT EXISTS user_password_resets (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                admin_id INT UNSIGNED NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                password_mask VARCHAR(60) NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                KEY upr_user_fk(user_id),
                KEY upr_admin_fk(admin_id)
            ) ENGINE=InnoDB');
        } catch (\Throwable $e) { /* ignore */
        }

        $where = [];
        $params = [];
        if ($userId !== null) {
            $where[] = 'upr.user_id=:uid';
            $params[':uid'] = $userId;
        }
        $sql = 'SELECT upr.id,upr.user_id,upr.admin_id,upr.password_mask,upr.created_at, u.name AS user_name, a.name AS admin_name '
            . 'FROM user_password_resets upr '
            . 'JOIN users u ON u.id=upr.user_id '
            . 'JOIN users a ON a.id=upr.admin_id';
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY upr.id DESC LIMIT 200';
        try {
            $rows = DB::query($sql, $params);
        } catch (\Throwable $e) {
            $rows = [];
        }
        echo json_encode($rows);
        exit;
    }
    // Désactivation utilisateur rapide
    if (preg_match('#^/api/v1/users/(\d+)/deactivate$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'PATCH') {
        $u = requireAuth();
        requirePermission($u, 'users', 'edit');
        $id = (int)$m[1];
        DB::execute('UPDATE users SET active=0, updated_at=NOW() WHERE id=:id', [':id' => $id]);
        echo json_encode(['deactivated' => true]);
        exit;
    }
    // Réactivation (optionnel)
    if (preg_match('#^/api/v1/users/(\d+)/activate$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'PATCH') {
        $u = requireAuth();
        requirePermission($u, 'users', 'edit');
        $id = (int)$m[1];
        DB::execute('UPDATE users SET active=1, updated_at=NOW() WHERE id=:id', [':id' => $id]);
        echo json_encode(['activated' => true]);
        exit;
    }
    // Orders (commandes, réception direct + stock IN)
    if ($path === '/api/v1/orders' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $u = requireAuth();
        requireRole($u, ['admin', 'gerant']);
        $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $items = $data['items'] ?? [];
        if (!$items) {
            http_response_code(422);
            echo json_encode(['error' => 'Items required']);
            exit;
        }
        $providedRef = trim($data['reference'] ?? '');
        if ($providedRef !== '') {
            // Vérifier unicité utilisateur fournie
            $exists = DB::query('SELECT id FROM orders WHERE reference=:r LIMIT 1', [':r' => $providedRef]);
            if ($exists) {
                http_response_code(409);
                echo json_encode(['error' => 'Référence déjà utilisée']);
                exit;
            }
            $ref = $providedRef;
        } else {
            // Génération auto jusqu'à unicité
            do {
                $candidate = 'PO-' . strtoupper(substr(md5(uniqid('', true)), 0, 8));
                $exists = DB::query('SELECT id FROM orders WHERE reference=:r LIMIT 1', [':r' => $candidate]);
            } while ($exists);
            $ref = $candidate;
        }
        $supplier = $data['supplier'] ?? null;
        $status = in_array(($data['status'] ?? 'ordered'), ['draft', 'ordered', 'received', 'cancelled', 'partially_received']) ? $data['status'] : 'ordered';
        if ($status === 'received' && empty($data['depot_id'])) {
            // Si réception immédiate demandée exiger depot_id
            http_response_code(422);
            echo json_encode(['error' => 'depot_id requis pour statut reçu']);
            exit;
        }
        $total = 0;
        foreach ($items as $it) {
            $total += ((int)$it['unit_cost'] * (int)$it['quantity']);
        }
        // Assurer colonne total_amount_remaining
        try {
            $colRemain = DB::query('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="orders" AND COLUMN_NAME="total_amount_remaining"');
            if (!$colRemain) {
                DB::execute('ALTER TABLE orders ADD COLUMN total_amount_remaining INT NOT NULL DEFAULT 0');
            }
        } catch (\Throwable $e) {
        }
        DB::execute('INSERT INTO orders(reference,supplier,status,total_amount,ordered_at,created_at) VALUES(:r,:s,:st,:t,NOW(),NOW())', [':r' => $ref, ':s' => $supplier, ':st' => $status, ':t' => $total]);
        $orderId = (int)DB::query('SELECT LAST_INSERT_ID() id')[0]['id'];
        DB::execute('UPDATE orders SET total_amount_remaining=:rem WHERE id=:id', [':rem' => $total, ':id' => $orderId]);
        foreach ($items as $it) {
            DB::execute('INSERT INTO order_items(order_id,product_id,initial_quantity,quantity,unit_cost,subtotal,created_at) VALUES(:o,:p,:iq,:q,:c,:st,NOW())', [':o' => $orderId, ':p' => (int)$it['product_id'], ':iq' => (int)$it['quantity'], ':q' => (int)$it['quantity'], ':c' => (int)$it['unit_cost'], ':st' => ((int)$it['unit_cost'] * (int)$it['quantity'])]);
            // Si reçu, on impacte le stock; sinon, on ne touche pas au stock
            if ($status === 'received') {
                $depotId = (int)($data['depot_id'] ?? 1);
                (new StockMovement())->move($depotId, (int)$it['product_id'], 'in', (int)$it['quantity'], date('Y-m-d H:i:s'), null, 'order:' . $ref);
                Stock::adjust($depotId, (int)$it['product_id'], 'in', (int)$it['quantity']);
            }
        }
        echo json_encode(['order_id' => $orderId, 'reference' => $ref, 'status' => $status]);
        exit;
    }
    if ($path === '/api/v1/orders' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        requireAuth();
        // Vérifier présence colonne total_amount_remaining (ajout rétro-actif si nécessaire)
        try {
            $colRemain = DB::query('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="orders" AND COLUMN_NAME="total_amount_remaining"');
            if (!$colRemain) {
                DB::execute('ALTER TABLE orders ADD COLUMN total_amount_remaining INT NOT NULL DEFAULT 0');
                DB::execute('UPDATE orders SET total_amount_remaining=total_amount WHERE total_amount_remaining=0');
            }
        } catch (\Throwable $e) {
            // En cas d'erreur on continue, SELECT avec COALESCE tombera sur total_amount
        }
        $where = [];
        $params = [];
        $status = trim($_GET['status'] ?? '');
        if ($status !== '' && in_array($status, ['draft', 'ordered', 'received', 'cancelled', 'partially_received'])) {
            $where[] = 'status = :st';
            $params[':st'] = $status;
        }
        $q = trim($_GET['q'] ?? '');
        if ($q !== '') {
            $where[] = '(reference LIKE :q OR supplier LIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }
        $sql = 'SELECT id,reference,supplier,status,total_amount,COALESCE(total_amount_remaining,total_amount) AS total_amount_remaining,ordered_at FROM orders';
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY ordered_at DESC LIMIT 200';
        $rows = DB::query($sql, $params);
        echo json_encode($rows);
        exit;
    }
    // Mark order as received (apply stock adjustments)
    if (preg_match('#^/api/v1/orders/(\d+)/receive$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'PATCH') {
        $u = requireAuth();
        requireRole($u, ['admin', 'gerant']);
        $id = (int)$m[1];
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $depotId = (int)($data['depot_id'] ?? 0);
        if ($depotId <= 0) {
            http_response_code(422);
            echo json_encode(['error' => 'depot_id requis']);
            exit;
        }
        $ord = DB::query('SELECT id,reference,status FROM orders WHERE id=:id LIMIT 1', [':id' => $id])[0] ?? null;
        if (!$ord) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            exit;
        }
        if (in_array(($ord['status'] ?? ''), ['received', 'cancelled']) && empty($data['items'])) {
            http_response_code(409);
            echo json_encode(['error' => 'Action non autorisée (statut final)']);
            exit;
        }
        $incomingItems = $data['items'] ?? null; // format: [{product_id, quantity}]
        $dbItems = DB::query('SELECT product_id, quantity, initial_quantity, unit_cost FROM order_items WHERE order_id=:o', [':o' => $id]);
        $ref = (string)$ord['reference'];
        if ($incomingItems && is_array($incomingItems) && count($incomingItems) > 0) {
            // Réception partielle
            $map = [];
            foreach ($dbItems as $di) {
                $map[(int)$di['product_id']] = (int)$di['quantity'];
            }
            foreach ($incomingItems as $ri) {
                $pid = (int)($ri['product_id'] ?? 0);
                $recvQty = max(0, (int)($ri['quantity'] ?? 0));
                if ($pid <= 0 || $recvQty <= 0) continue;
                $remaining = $map[$pid] ?? 0;
                if ($remaining <= 0) continue; // déjà tout reçu
                if ($recvQty > $remaining) $recvQty = $remaining; // limiter
                // Mouvement & ajustement
                (new StockMovement())->move($depotId, $pid, 'in', $recvQty, date('Y-m-d H:i:s'), null, 'order:' . $ref);
                Stock::adjust($depotId, $pid, 'in', $recvQty);
                // Réduire quantité restante dans la ligne
                DB::execute('UPDATE order_items SET quantity=quantity-:q WHERE order_id=:o AND product_id=:p AND quantity>=:q', [':q' => $recvQty, ':o' => $id, ':p' => $pid]);
                $map[$pid] -= $recvQty;
            }
            // Recalcul statut + montants restants
            $remainRows = DB::query('SELECT SUM(quantity) AS remain, SUM(quantity*unit_cost) AS remain_total FROM order_items WHERE order_id=:o', [':o' => $id]);
            $remain = (int)($remainRows[0]['remain'] ?? 0);
            $remainTotal = (int)($remainRows[0]['remain_total'] ?? 0);
            $newStatus = $remain > 0 ? 'partially_received' : 'received';
            DB::execute('UPDATE orders SET status=:st,total_amount_remaining=:rt,updated_at=NOW() WHERE id=:id', [':st' => $newStatus, ':rt' => $remainTotal, ':id' => $id]);
            echo json_encode(['received' => true, 'partial' => $remain > 0, 'remaining' => $remain, 'remaining_total' => $remainTotal, 'status' => $newStatus]);
        } else {
            // Réception totale (ancienne logique)
            foreach ($dbItems as $it) {
                $pid = (int)$it['product_id'];
                $qty = (int)$it['quantity']; // restant
                if ($qty > 0) {
                    (new StockMovement())->move($depotId, $pid, 'in', $qty, date('Y-m-d H:i:s'), null, 'order:' . $ref);
                    Stock::adjust($depotId, $pid, 'in', $qty);
                }
                // mettre à zéro quantité restante
                DB::execute('UPDATE order_items SET quantity=0 WHERE order_id=:o AND product_id=:p', [':o' => $id, ':p' => $pid]);
            }
            DB::execute('UPDATE orders SET status="received", total_amount_remaining=0, ordered_at=NOW(), updated_at=NOW() WHERE id=:id', [':id' => $id]);
            echo json_encode(['received' => true, 'partial' => false, 'remaining' => 0, 'remaining_total' => 0, 'status' => 'received']);
        }
        exit;
    }
    // Cancel order
    if (preg_match('#^/api/v1/orders/(\d+)/cancel$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'PATCH') {
        $u = requireAuth();
        requireRole($u, ['admin', 'gerant']);
        $id = (int)$m[1];
        $ord = DB::query('SELECT id,status FROM orders WHERE id=:id LIMIT 1', [':id' => $id])[0] ?? null;
        if (!$ord) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            exit;
        }
        if (in_array(($ord['status'] ?? ''), ['received', 'cancelled', 'partially_received'])) {
            http_response_code(409);
            echo json_encode(['error' => 'Statut final']);
            exit;
        }
        DB::execute('UPDATE orders SET status="cancelled", updated_at=NOW() WHERE id=:id', [':id' => $id]);
        echo json_encode(['cancelled' => true, 'id' => $id]);
        exit;
    }
    // Get single order with items
    if (preg_match('#^/api/v1/orders/(\d+)$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'GET') {
        requireAuth();
        $id = (int)$m[1];
        // Même vérification colonne pour consultation individuelle
        try {
            $colRemain = DB::query('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="orders" AND COLUMN_NAME="total_amount_remaining"');
            if (!$colRemain) {
                DB::execute('ALTER TABLE orders ADD COLUMN total_amount_remaining INT NOT NULL DEFAULT 0');
                DB::execute('UPDATE orders SET total_amount_remaining=total_amount WHERE total_amount_remaining=0');
            }
        } catch (\Throwable $e) {
        }
        $ord = DB::query('SELECT id,reference,supplier,status,total_amount,COALESCE(total_amount_remaining,total_amount) AS total_amount_remaining,ordered_at,created_at FROM orders WHERE id=:id LIMIT 1', [':id' => $id])[0] ?? null;
        if (!$ord) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            exit;
        }
        $items = DB::query('SELECT oi.product_id, p.name AS product_name, oi.initial_quantity, oi.quantity, oi.unit_cost, oi.subtotal FROM order_items oi JOIN products p ON p.id=oi.product_id WHERE oi.order_id=:o', [':o' => $id]);
        echo json_encode(['order' => $ord, 'items' => $items]);
        exit;
    }
    // Proposed order items based on current stock vs target
    if ($path === '/api/v1/orders/proposals' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        requireAuth();
        $target = max(0, (int)($_GET['target'] ?? 10));
        $depotId = isset($_GET['depot_id']) ? (int)$_GET['depot_id'] : null;
        // Current stock per product (global or per depot)
        if ($depotId) {
            $rows = DB::query('SELECT p.id, p.name, p.sku, p.unit_price, COALESCE(s.quantity,0) AS qty FROM products p LEFT JOIN stocks s ON s.product_id=p.id AND s.depot_id=:d ORDER BY p.name ASC', [':d' => $depotId]);
        } else {
            $rows = DB::query('SELECT p.id, p.name, p.sku, p.unit_price, (SELECT COALESCE(SUM(s.quantity),0) FROM stocks s WHERE s.product_id=p.id) AS qty FROM products p ORDER BY p.name ASC');
        }
        $items = [];
        foreach ($rows as $r) {
            $need = $target - (int)$r['qty'];
            if ($need > 0) {
                $items[] = [
                    'product_id' => (int)$r['id'],
                    'product_name' => $r['name'],
                    'current_stock' => (int)$r['qty'],
                    'suggested_qty' => $need,
                    'unit_cost' => (int)$r['unit_price'],
                ];
            }
        }
        echo json_encode(['target' => $target, 'depot_id' => $depotId, 'items' => $items]);
        exit;
    }
    // Stock transfer endpoint
    if ($path === '/api/v1/stock/transfer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $u = requireAuth();
        requireRole($u, ['admin', 'gerant']);
        $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        foreach (['from_depot_id', 'to_depot_id', 'product_id', 'quantity'] as $f) {
            if (empty($data[$f])) {
                http_response_code(422);
                echo json_encode(['error' => 'Missing ' . $f]);
                exit;
            }
        }
        $from = (int)$data['from_depot_id'];
        $to = (int)$data['to_depot_id'];
        $pid = (int)$data['product_id'];
        $qty = (int)$data['quantity'];
        if ($qty <= 0) {
            http_response_code(422);
            echo json_encode(['error' => 'Qty must be > 0']);
            exit;
        }
        $available = Stock::available($from, $pid);
        if ($available < $qty) {
            http_response_code(422);
            echo json_encode(['error' => 'INSUFFICIENT_STOCK', 'available' => $available]);
            exit;
        }
        (new StockMovement())->move($from, $pid, 'transfer', $qty, date('Y-m-d H:i:s'), null, 'to:' . $to);
        Stock::adjust($from, $pid, 'out', $qty);
        (new StockMovement())->move($to, $pid, 'in', $qty, date('Y-m-d H:i:s'), null, 'from:' . $from);
        Stock::adjust($to, $pid, 'in', $qty);
        echo json_encode(['transferred' => true, 'from' => $from, 'to' => $to, 'product_id' => $pid, 'quantity' => $qty]);
        exit;
    }
    // Stock transfers history
    if ($path === '/api/v1/stock/transfers' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        requireAuth();
        $where = ['t.type = "transfer"'];
        $params = [];
        if (!empty($_GET['product_id'])) {
            $where[] = 't.product_id = :pid';
            $params[':pid'] = (int)$_GET['product_id'];
        }
        if (!empty($_GET['from_depot_id'])) {
            $where[] = 't.depot_id = :fromd';
            $params[':fromd'] = (int)$_GET['from_depot_id'];
        }
        if (!empty($_GET['to_depot_id'])) {
            $where[] = 'to_id = :tod';
            $params[':tod'] = (int)$_GET['to_depot_id'];
        }
        if (!empty($_GET['from'])) {
            $where[] = 't.moved_at >= :fromdte';
            $params[':fromdte'] = $_GET['from'] . ' 00:00:00';
        }
        if (!empty($_GET['to'])) {
            $where[] = 't.moved_at <= :todte';
            $params[':todte'] = $_GET['to'] . ' 23:59:59';
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $limit = (int)($_GET['limit'] ?? 200);
        if ($limit <= 0 || $limit > 1000) $limit = 200;
        $sql = 'SELECT t.id, t.product_id, p.name AS product_name, t.quantity, t.depot_id AS from_depot_id, df.name AS from_depot_name, df.code AS from_depot_code, '
            . 'CASE WHEN t.note LIKE "to:%" THEN CAST(SUBSTRING(t.note,4) AS UNSIGNED) ELSE NULL END AS to_depot_id, '
            . 'dt.name AS to_depot_name, dt.code AS to_depot_code, t.moved_at '
            . 'FROM stock_movements t '
            . 'JOIN products p ON p.id = t.product_id '
            . 'JOIN depots df ON df.id = t.depot_id '
            . 'LEFT JOIN depots dt ON dt.id = CASE WHEN t.note LIKE "to:%" THEN CAST(SUBSTRING(t.note,4) AS UNSIGNED) ELSE NULL END '
            . $whereSql . ' ORDER BY t.moved_at DESC LIMIT ' . $limit;
        $rows = DB::query($sql, $params);
        echo json_encode($rows);
        exit;
    }
    if ($path === '/api/v1/reports/daily') {
        $depotId = (int)($_GET['depot_id'] ?? 1);
        $date = $_GET['date'] ?? date('Y-m-d');
        echo json_encode(ReportService::daily($depotId, $date));
        exit;
    }
    if ($path === '/api/v1/reports/monthly') {
        $depotId = (int)($_GET['depot_id'] ?? 1);
        $month = $_GET['month'] ?? date('Y-m');
        echo json_encode(ReportService::monthly($depotId, $month));
        exit;
    }
    // Financial & Stock summary (point financier & stock)
    if ($path === '/api/v1/finance-stock' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $auth = requireAuth();
        requireUserCan($auth, 'finance_stock', 'view');
        $role = (string)($auth['role'] ?? '');
        $userDepotId = (int)($auth['depot_id'] ?? 0);
        $paramDepot = isset($_GET['depot_id']) && $_GET['depot_id'] !== '' ? (int)$_GET['depot_id'] : null;
        $from = trim($_GET['from'] ?? '');
        $to = trim($_GET['to'] ?? '');
        // S'assurer que la colonne cost_price existe pour la valorisation
        try {
            $col = DB::query('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="products" AND COLUMN_NAME="cost_price"');
            if (!$col) {
                DB::execute('ALTER TABLE products ADD COLUMN cost_price INT NOT NULL DEFAULT 0 AFTER unit_price');
                // Initialiser avec le prix de vente si coût inconnu
                DB::execute('UPDATE products SET cost_price = unit_price WHERE cost_price = 0');
            }
        } catch (\Throwable $e) { /* ignore */
        }
        // Scope stock (admin can pick depot, manager fixed to own depot)
        $stockWhere = [];
        $stockParams = [];
        if ($role === 'admin') {
            if ($paramDepot && $paramDepot > 0) {
                $stockWhere[] = 's.depot_id = :dep';
                $stockParams[':dep'] = $paramDepot;
            }
        } elseif ($role === 'gerant' && $userDepotId > 0) {
            $stockWhere[] = 's.depot_id = :dep';
            $stockParams[':dep'] = $userDepotId;
        } else {
            // livreur & autres: forcer au dépôt utilisateur
            if ($userDepotId > 0) {
                $stockWhere[] = 's.depot_id = :dep';
                $stockParams[':dep'] = $userDepotId;
            } else {
                // aucun dépôt rattaché: renvoyer vide
                echo json_encode(['by_depot' => [], 'stock_totals' => ['qty' => 0, 'valuation' => 0], 'client_balances' => [], 'receivables_total' => 0, 'filters' => ['depot_id' => null, 'from' => $from, 'to' => $to]]);
                exit;
            }
        }
        $stockScopeSql = $stockWhere ? (' WHERE ' . implode(' AND ', $stockWhere)) : '';
        // Stock by depot
        $byDepot = DB::query('SELECT s.depot_id, d.name AS depot_name, COALESCE(SUM(s.quantity),0) qty, COALESCE(SUM(s.quantity * p.cost_price),0) valuation '
            . 'FROM stocks s JOIN products p ON p.id=s.product_id JOIN depots d ON d.id=s.depot_id'
            . $stockScopeSql . ' GROUP BY s.depot_id, d.name ORDER BY d.name ASC', $stockParams);
        $stockTotals = DB::query('SELECT COALESCE(SUM(s.quantity),0) qty, COALESCE(SUM(s.quantity * p.cost_price),0) valuation '
            . 'FROM stocks s JOIN products p ON p.id=s.product_id' . $stockScopeSql, $stockParams)[0] ?? ['qty' => 0, 'valuation' => 0];
        // Client balances (global or by depot scope via sales join if applicable)
        $salesWhere = [];
        $salesParams = [];
        if ($role === 'admin') {
            if ($paramDepot && $paramDepot > 0) {
                $salesWhere[] = 's.depot_id = :sdep';
                $salesParams[':sdep'] = $paramDepot;
            }
        } elseif ($role === 'gerant' && $userDepotId > 0) {
            $salesWhere[] = 's.depot_id = :sdep';
            $salesParams[':sdep'] = $userDepotId;
        } else {
            if ($userDepotId > 0) {
                $salesWhere[] = 's.depot_id = :sdep';
                $salesParams[':sdep'] = $userDepotId;
            } else {
                echo json_encode(['by_depot' => [], 'stock_totals' => ['qty' => 0, 'valuation' => 0], 'client_balances' => [], 'receivables_total' => 0, 'filters' => ['depot_id' => null, 'from' => $from, 'to' => $to]]);
                exit;
            }
        }
        if ($from !== '') {
            $salesWhere[] = 's.sold_at >= :fromd';
            $salesParams[':fromd'] = $from . ' 00:00:00';
        }
        if ($to !== '') {
            $salesWhere[] = 's.sold_at <= :tod';
            $salesParams[':tod'] = $to . ' 23:59:59';
        }
        $salesScope = $salesWhere ? (' WHERE ' . implode(' AND ', $salesWhere)) : '';
        $clients = DB::query('SELECT c.id, c.name, COALESCE(SUM(s.total_amount) - SUM(s.amount_paid),0) AS balance '
            . 'FROM sales s JOIN clients c ON c.id = s.client_id' . $salesScope
            . ' GROUP BY c.id,c.name HAVING balance <> 0 ORDER BY balance DESC LIMIT 1000', $salesParams);
        $balancesTotal = (int)(DB::query('SELECT COALESCE(SUM(total_amount - amount_paid),0) v FROM sales s' . $salesScope, $salesParams)[0]['v'] ?? 0);
        echo json_encode([
            'by_depot' => $byDepot,
            'stock_totals' => ['qty' => (int)($stockTotals['qty'] ?? 0), 'valuation' => (int)($stockTotals['valuation'] ?? 0)],
            'client_balances' => $clients,
            'receivables_total' => $balancesTotal,
            'filters' => ['depot_id' => $paramDepot, 'from' => $from, 'to' => $to]
        ]);
        exit;
    }
    // Finance & stock export CSV
    if ($path === '/api/v1/finance-stock/export' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $auth = requireAuth();
        requireUserCan($auth, 'finance_stock', 'export');
        // Reuse API to gather
        $_SERVER['REQUEST_METHOD'] = 'GET';
        // recompute inline to avoid nested dispatch
        $role = (string)($auth['role'] ?? '');
        $userDepotId = (int)($auth['depot_id'] ?? 0);
        $paramDepot = isset($_GET['depot_id']) && $_GET['depot_id'] !== '' ? (int)$_GET['depot_id'] : null;
        $from = trim($_GET['from'] ?? '');
        $to = trim($_GET['to'] ?? '');
        // S'assurer que la colonne cost_price existe
        try {
            $col = DB::query('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="products" AND COLUMN_NAME="cost_price"');
            if (!$col) {
                DB::execute('ALTER TABLE products ADD COLUMN cost_price INT NOT NULL DEFAULT 0 AFTER unit_price');
                DB::execute('UPDATE products SET cost_price = unit_price WHERE cost_price = 0');
            }
        } catch (\Throwable $e) { /* ignore */
        }
        $stockWhere = [];
        $stockParams = [];
        if ($role === 'admin') {
            if ($paramDepot && $paramDepot > 0) {
                $stockWhere[] = 's.depot_id = :dep';
                $stockParams[':dep'] = $paramDepot;
            }
        } elseif ($role === 'gerant' && $userDepotId > 0) {
            $stockWhere[] = 's.depot_id = :dep';
            $stockParams[':dep'] = $userDepotId;
        } else {
            if ($userDepotId > 0) {
                $stockWhere[] = 's.depot_id = :dep';
                $stockParams[':dep'] = $userDepotId;
            } else {
                $stockWhere[] = '1=0';
            }
        }
        $stockScopeSql = $stockWhere ? (' WHERE ' . implode(' AND ', $stockWhere)) : '';
        $byDepot = DB::query('SELECT s.depot_id, d.name AS depot_name, COALESCE(SUM(s.quantity),0) qty, COALESCE(SUM(s.quantity * p.cost_price),0) valuation '
            . 'FROM stocks s JOIN products p ON p.id=s.product_id JOIN depots d ON d.id=s.depot_id'
            . $stockScopeSql . ' GROUP BY s.depot_id, d.name ORDER BY d.name ASC', $stockParams);
        $stockTotals = DB::query('SELECT COALESCE(SUM(s.quantity),0) qty, COALESCE(SUM(s.quantity * p.cost_price),0) valuation '
            . 'FROM stocks s JOIN products p ON p.id=s.product_id' . $stockScopeSql, $stockParams)[0] ?? ['qty' => 0, 'valuation' => 0];
        $salesWhere = [];
        $salesParams = [];
        if ($role === 'admin') {
            if ($paramDepot && $paramDepot > 0) {
                $salesWhere[] = 's.depot_id = :sdep';
                $salesParams[':sdep'] = $paramDepot;
            }
        } elseif ($role === 'gerant' && $userDepotId > 0) {
            $salesWhere[] = 's.depot_id = :sdep';
            $salesParams[':sdep'] = $userDepotId;
        } else {
            if ($userDepotId > 0) {
                $salesWhere[] = 's.depot_id = :sdep';
                $salesParams[':sdep'] = $userDepotId;
            } else {
                $salesWhere[] = '1=0';
            }
        }
        if ($from !== '') {
            $salesWhere[] = 's.sold_at >= :fromd';
            $salesParams[':fromd'] = $from . ' 00:00:00';
        }
        if ($to !== '') {
            $salesWhere[] = 's.sold_at <= :tod';
            $salesParams[':tod'] = $to . ' 23:59:59';
        }
        $salesScope = $salesWhere ? (' WHERE ' . implode(' AND ', $salesWhere)) : '';
        $clients = DB::query('SELECT c.id, c.name, COALESCE(SUM(s.total_amount) - SUM(s.amount_paid),0) AS balance '
            . 'FROM sales s JOIN clients c ON c.id = s.client_id' . $salesScope
            . ' GROUP BY c.id,c.name HAVING balance <> 0 ORDER BY balance DESC LIMIT 1000', $salesParams);
        $balancesTotal = (int)(DB::query('SELECT COALESCE(SUM(total_amount - amount_paid),0) v FROM sales s' . $salesScope, $salesParams)[0]['v'] ?? 0);

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="finance_stock.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Point financier & stock']);
        fputcsv($out, ['Période', $from ?: '-', $to ?: '-']);
        fputcsv($out, ['Dépôt', ($paramDepot ?: ($role === 'gerant' ? $userDepotId : 'Tous'))]);
        fputcsv($out, []);
        fputcsv($out, ['Stocks par dépôt']);
        fputcsv($out, ['Dépôt', 'Quantité', 'Valorisation']);
        foreach ($byDepot as $r) fputcsv($out, [$r['depot_name'], (int)$r['qty'], format_fcfa((int)$r['valuation'])]);
        fputcsv($out, ['TOTAL', (int)($stockTotals['qty'] ?? 0), format_fcfa((int)($stockTotals['valuation'] ?? 0))]);
        fputcsv($out, []);
        fputcsv($out, ['Soldes clients (≠ 0)']);
        fputcsv($out, ['Client', 'Solde']);
        foreach ($clients as $c) fputcsv($out, [$c['name'], format_fcfa((int)$c['balance'])]);
        fputcsv($out, ['Encours total', format_fcfa($balancesTotal)]);
        fclose($out);
        try {
            audit_log((int)$auth['id'], 'export', 'finance_stock', null, $path, 'GET', ['from' => $from, 'to' => $to, 'depot' => $paramDepot]);
        } catch (\Throwable $e) {
        }
        exit;
    }
    // Finance & stock export PDF
    if ($path === '/api/v1/finance-stock/export-pdf' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $auth = requireAuth();
        requireUserCan($auth, 'finance_stock', 'export');
        if (!class_exists('TCPDF')) {
            try {
                @include_once __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php';
            } catch (\Throwable $e) {
            }
        }
        if (!class_exists('TCPDF')) {
            http_response_code(500);
            echo 'TCPDF non installé. Installez avec: composer require tecnickcom/tcpdf';
            exit;
        }
        $role = (string)($auth['role'] ?? '');
        $userDepotId = (int)($auth['depot_id'] ?? 0);
        $paramDepot = isset($_GET['depot_id']) && $_GET['depot_id'] !== '' ? (int)$_GET['depot_id'] : null;
        $from = trim($_GET['from'] ?? '');
        $to = trim($_GET['to'] ?? '');
        // S'assurer que la colonne cost_price existe
        try {
            $col = DB::query('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="products" AND COLUMN_NAME="cost_price"');
            if (!$col) {
                DB::execute('ALTER TABLE products ADD COLUMN cost_price INT NOT NULL DEFAULT 0 AFTER unit_price');
                DB::execute('UPDATE products SET cost_price = unit_price WHERE cost_price = 0');
            }
        } catch (\Throwable $e) { /* ignore */
        }
        $stockWhere = [];
        $stockParams = [];
        if ($role === 'admin') {
            if ($paramDepot && $paramDepot > 0) {
                $stockWhere[] = 's.depot_id = :dep';
                $stockParams[':dep'] = $paramDepot;
            }
        } elseif ($role === 'gerant' && $userDepotId > 0) {
            $stockWhere[] = 's.depot_id = :dep';
            $stockParams[':dep'] = $userDepotId;
        } else {
            if ($userDepotId > 0) {
                $stockWhere[] = 's.depot_id = :dep';
                $stockParams[':dep'] = $userDepotId;
            } else {
                $stockWhere[] = '1=0';
            }
        }
        $stockScopeSql = $stockWhere ? (' WHERE ' . implode(' AND ', $stockWhere)) : '';
        $byDepot = DB::query('SELECT s.depot_id, d.name AS depot_name, COALESCE(SUM(s.quantity),0) qty, COALESCE(SUM(s.quantity * p.cost_price),0) valuation '
            . 'FROM stocks s JOIN products p ON p.id=s.product_id JOIN depots d ON d.id=s.depot_id' . $stockScopeSql . ' GROUP BY s.depot_id, d.name ORDER BY d.name ASC', $stockParams);
        $stockTotals = DB::query('SELECT COALESCE(SUM(s.quantity),0) qty, COALESCE(SUM(s.quantity * p.cost_price),0) valuation '
            . 'FROM stocks s JOIN products p ON p.id=s.product_id' . $stockScopeSql, $stockParams)[0] ?? ['qty' => 0, 'valuation' => 0];
        $salesWhere = [];
        $salesParams = [];
        if ($role === 'admin') {
            if ($paramDepot && $paramDepot > 0) {
                $salesWhere[] = 's.depot_id = :sdep';
                $salesParams[':sdep'] = $paramDepot;
            }
        } elseif ($role === 'gerant' && $userDepotId > 0) {
            $salesWhere[] = 's.depot_id = :sdep';
            $salesParams[':sdep'] = $userDepotId;
        } else {
            if ($userDepotId > 0) {
                $salesWhere[] = 's.depot_id = :sdep';
                $salesParams[':sdep'] = $userDepotId;
            } else {
                $salesWhere[] = '1=0';
            }
        }
        if ($from !== '') {
            $salesWhere[] = 's.sold_at >= :fromd';
            $salesParams[':fromd'] = $from . ' 00:00:00';
        }
        if ($to !== '') {
            $salesWhere[] = 's.sold_at <= :tod';
            $salesParams[':tod'] = $to . ' 23:59:59';
        }
        $salesScope = $salesWhere ? (' WHERE ' . implode(' AND ', $salesWhere)) : '';
        $clients = DB::query('SELECT c.id, c.name, COALESCE(SUM(s.total_amount) - SUM(s.amount_paid),0) AS balance '
            . 'FROM sales s JOIN clients c ON c.id = s.client_id' . $salesScope
            . ' GROUP BY c.id,c.name HAVING balance <> 0 ORDER BY balance DESC LIMIT 1000', $salesParams);
        $balancesTotal = (int)(DB::query('SELECT COALESCE(SUM(total_amount - amount_paid),0) v FROM sales s' . $salesScope, $salesParams)[0]['v'] ?? 0);

        $pdf = new \TCPDF('P', 'mm', 'A4');
        $pdf->SetCreator('Hill Stock');
        $pdf->SetAuthor('Hill Stock');
        $pdf->SetTitle('Point financier & stock');
        $pdf->AddPage();
        $html = '<h2 style="margin:0 0 6px">Point financier & stock</h2>';
        $html .= '<div style="font-size:10px;color:#666">Généré le ' . htmlspecialchars(date('Y-m-d H:i')) . ' — Période: ' . htmlspecialchars($from ?: '-') . ' → ' . htmlspecialchars($to ?: '-') . '</div>';
        $html .= '<br />';
        $html .= '<h4>Stocks par dépôt</h4><table border="1" cellpadding="4"><thead><tr><th>Dépôt</th><th>Quantité</th><th>Valorisation</th></tr></thead><tbody>';
        foreach ($byDepot as $r) {
            $html .= '<tr><td>' . htmlspecialchars($r['depot_name']) . '</td><td align="right">' . (int)$r['qty'] . '</td><td align="right">' . htmlspecialchars(format_fcfa((int)$r['valuation'])) . '</td></tr>';
        }
        $html .= '<tr><th align="right">TOTAL</th><th align="right">' . (int)($stockTotals['qty'] ?? 0) . '</th><th align="right">' . htmlspecialchars(format_fcfa((int)($stockTotals['valuation'] ?? 0))) . '</th></tr>';
        $html .= '</tbody></table><br />';
        $html .= '<h4>Soldes clients (≠ 0)</h4><table border="1" cellpadding="4"><thead><tr><th>Client</th><th>Solde</th></tr></thead><tbody>';
        foreach ($clients as $c) {
            $html .= '<tr><td>' . htmlspecialchars($c['name']) . '</td><td align="right">' . htmlspecialchars(format_fcfa((int)$c['balance'])) . '</td></tr>';
        }
        $html .= '<tr><th align="right">Encours total</th><th align="right">' . htmlspecialchars(format_fcfa($balancesTotal)) . '</th></tr>';
        $html .= '</tbody></table>';
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Output('point_financier_stock.pdf', 'I');
        try {
            audit_log((int)$auth['id'], 'export', 'finance_stock', null, $path, 'GET', ['from' => $from, 'to' => $to, 'depot' => $paramDepot]);
        } catch (\Throwable $e) {
        }
        exit;
    }
    // (removed) duplicate /api/v1/summary without permission scoping
    // Audit logs listing (admin)
    if ($path === '/api/v1/audit-logs' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $u = requireAuth();
        requireRole($u, ['admin']);
        ensure_audit_table();
        $where = [];
        $params = [];
        $action = trim($_GET['action'] ?? '');
        $entity = trim($_GET['entity'] ?? '');
        $userId = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int)$_GET['user_id'] : null;
        $from = trim($_GET['from'] ?? '');
        $to = trim($_GET['to'] ?? '');
        $q = trim($_GET['q'] ?? '');
        $limit = (int)($_GET['limit'] ?? 200);
        if ($limit <= 0 || $limit > 1000) $limit = 200;
        if ($action !== '') {
            $where[] = 'al.action = :ac';
            $params[':ac'] = $action;
        }
        if ($entity !== '') {
            $where[] = 'al.entity = :en';
            $params[':en'] = $entity;
        }
        if ($userId !== null) {
            $where[] = 'al.actor_user_id = :uid';
            $params[':uid'] = $userId;
        }
        if ($from !== '') {
            $where[] = 'al.created_at >= :from';
            $params[':from'] = $from . ' 00:00:00';
        }
        if ($to !== '') {
            $where[] = 'al.created_at <= :to';
            $params[':to'] = $to . ' 23:59:59';
        }
        if ($q !== '') {
            $where[] = '(al.route LIKE :q OR al.entity LIKE :q OR al.action LIKE :q OR u.name LIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }
        $sql = 'SELECT al.id, al.actor_user_id, u.name AS actor_name, al.action, al.entity, al.entity_id, al.route, al.method, al.ip, al.user_agent, al.created_at '
            . 'FROM audit_logs al LEFT JOIN users u ON u.id = al.actor_user_id';
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY al.id DESC LIMIT ' . $limit;
        $rows = DB::query($sql, $params);
        echo json_encode($rows);
        exit;
    }
    if ($path === '/api/v1/audit-logs/export' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        // CSV export
        $u = requireAuth();
        requireRole($u, ['admin']);
        ensure_audit_table();
        $where = [];
        $params = [];
        $action = trim($_GET['action'] ?? '');
        $entity = trim($_GET['entity'] ?? '');
        $userId = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int)$_GET['user_id'] : null;
        $from = trim($_GET['from'] ?? '');
        $to = trim($_GET['to'] ?? '');
        $q = trim($_GET['q'] ?? '');
        if ($action !== '') {
            $where[] = 'al.action = :ac';
            $params[':ac'] = $action;
        }
        if ($entity !== '') {
            $where[] = 'al.entity = :en';
            $params[':en'] = $entity;
        }
        if ($userId !== null) {
            $where[] = 'al.actor_user_id = :uid';
            $params[':uid'] = $userId;
        }
        if ($from !== '') {
            $where[] = 'al.created_at >= :from';
            $params[':from'] = $from . ' 00:00:00';
        }
        if ($to !== '') {
            $where[] = 'al.created_at <= :to';
            $params[':to'] = $to . ' 23:59:59';
        }
        if ($q !== '') {
            $where[] = '(al.route LIKE :q OR al.entity LIKE :q OR al.action LIKE :q OR u.name LIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }
        $sql = 'SELECT al.id, al.actor_user_id, u.name AS actor_name, al.action, al.entity, al.entity_id, al.route, al.method, al.ip, al.user_agent, al.created_at '
            . 'FROM audit_logs al LEFT JOIN users u ON u.id = al.actor_user_id';
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY al.id DESC LIMIT 5000';
        $rows = DB::query($sql, $params);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="audit_logs.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['id', 'user_id', 'user_name', 'action', 'entity', 'entity_id', 'route', 'method', 'ip', 'user_agent', 'created_at']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['id'],
                $r['actor_user_id'],
                $r['actor_name'] ?? '',
                $r['action'],
                $r['entity'],
                $r['entity_id'],
                $r['route'],
                $r['method'],
                $r['ip'],
                $r['user_agent'],
                $r['created_at']
            ]);
        }
        fclose($out);
        exit;
    }
    if ($path === '/api/v1/audit-logs/export-pdf' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $u = requireAuth();
        requireRole($u, ['admin']);
        ensure_audit_table();
        // Charger TCPDF si nécessaire
        if (!class_exists('TCPDF')) {
            try {
                @include_once __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php';
            } catch (\Throwable $e) {
            }
        }
        if (!class_exists('TCPDF')) {
            http_response_code(500);
            echo 'TCPDF non installé. Installez avec: composer require tecnickcom/tcpdf';
            exit;
        }
        $where = [];
        $params = [];
        $action = trim($_GET['action'] ?? '');
        $entity = trim($_GET['entity'] ?? '');
        $userId = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int)$_GET['user_id'] : null;
        $from = trim($_GET['from'] ?? '');
        $to = trim($_GET['to'] ?? '');
        $q = trim($_GET['q'] ?? '');
        $limit = (int)($_GET['limit'] ?? 1000);
        if ($limit <= 0 || $limit > 2000) $limit = 1000;
        if ($action !== '') {
            $where[] = 'al.action = :ac';
            $params[':ac'] = $action;
        }
        if ($entity !== '') {
            $where[] = 'al.entity = :en';
            $params[':en'] = $entity;
        }
        if ($userId !== null) {
            $where[] = 'al.actor_user_id = :uid';
            $params[':uid'] = $userId;
        }
        if ($from !== '') {
            $where[] = 'al.created_at >= :from';
            $params[':from'] = $from . ' 00:00:00';
        }
        if ($to !== '') {
            $where[] = 'al.created_at <= :to';
            $params[':to'] = $to . ' 23:59:59';
        }
        if ($q !== '') {
            $where[] = '(al.route LIKE :q OR al.entity LIKE :q OR al.action LIKE :q OR u.name LIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }
        $sql = 'SELECT al.id, al.actor_user_id, u.name AS actor_name, al.action, al.entity, al.entity_id, al.route, al.method, al.ip, al.user_agent, al.created_at '
            . 'FROM audit_logs al LEFT JOIN users u ON u.id = al.actor_user_id';
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY al.id DESC LIMIT ' . $limit;
        $rows = DB::query($sql, $params);

        // Audit explicite export
        try {
            audit_log((int)$u['id'], 'export', 'audit_logs', null, $path, 'GET');
        } catch (\Throwable $e) {
        }

        $pdf = new \TCPDF('L', 'mm', 'A4');
        $pdf->SetCreator('Hill Stock');
        $pdf->SetAuthor('Hill Stock');
        $pdf->SetTitle('Audit logs');
        $pdf->AddPage();
        $html = '<h2 style="font-size:16px;margin:0 0 6px">Journal d\'audit</h2>';
        $html .= '<div style="font-size:10px;color:#666">Généré le ' . htmlspecialchars(date('Y-m-d H:i')) . '</div>';
        $html .= '<br />';
        $html .= '<table border="1" cellpadding="4" cellspacing="0"><thead><tr style="background:#f2f2f2;font-weight:bold">'
            . '<th width="5%">ID</th>'
            . '<th width="15%">Utilisateur</th>'
            . '<th width="10%">Action</th>'
            . '<th width="12%">Entité</th>'
            . '<th width="8%">Entité ID</th>'
            . '<th width="25%">Route</th>'
            . '<th width="7%">Méthode</th>'
            . '<th width="8%">IP</th>'
            . '<th width="10%">Date</th>'
            . '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $html .= '<tr>'
                . '<td>' . (int)$r['id'] . '</td>'
                . '<td>' . htmlspecialchars(($r['actor_name'] ?? '') !== '' ? $r['actor_name'] : ('#' . (string)($r['actor_user_id'] ?? ''))) . '</td>'
                . '<td>' . htmlspecialchars((string)$r['action']) . '</td>'
                . '<td>' . htmlspecialchars((string)($r['entity'] ?? '')) . '</td>'
                . '<td>' . htmlspecialchars((string)($r['entity_id'] ?? '')) . '</td>'
                . '<td>' . htmlspecialchars((string)$r['route']) . '</td>'
                . '<td>' . htmlspecialchars((string)$r['method']) . '</td>'
                . '<td>' . htmlspecialchars((string)($r['ip'] ?? '')) . '</td>'
                . '<td>' . htmlspecialchars((string)$r['created_at']) . '</td>'
                . '</tr>';
        }
        $html .= '</tbody></table>';
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Output('audit_logs.pdf', 'I');
        exit;
    }
    // Create seller round (assignment) - admin/gerant
    if ($path === '/api/v1/seller-rounds' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $u = requireAuth();
        requireRole($u, ['admin', 'gerant']);
        ensure_seller_rounds_tables();
        $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $depotId = (int)($data['depot_id'] ?? 0);
        if ($depotId <= 0) {
            // fallback to user's depot when not provided, per UI guidance
            $depotId = (int)($u['depot_id'] ?? 0);
        }
        $sellerId = (int)($data['user_id'] ?? 0);
        $items = $data['items'] ?? [];
        if ($depotId <= 0 || $sellerId <= 0 || !is_array($items) || count($items) === 0) {
            http_response_code(422);
            echo json_encode(['error' => 'depot_id, user_id et items requis']);
            exit;
        }
        // Strict stock validation before dispatch
        $insuff = [];
        foreach ($items as $it) {
            $pid = (int)($it['product_id'] ?? 0);
            $qty = max(0, (int)($it['quantity'] ?? 0));
            if ($pid <= 0 || $qty <= 0) continue;
            $avail = Stock::available($depotId, $pid);
            if ($avail < $qty) {
                $insuff[] = ['product_id' => $pid, 'requested' => $qty, 'available' => (int)$avail];
            }
        }
        if (!empty($insuff)) {
            http_response_code(422);
            echo json_encode(['error' => 'INSUFFICIENT_STOCK', 'details' => $insuff]);
            exit;
        }
        // Create round
        DB::execute('INSERT INTO seller_rounds(depot_id,user_id,status,assigned_at) VALUES(:d,:u,"open",NOW())', [':d' => $depotId, ':u' => $sellerId]);
        $rid = (int)DB::query('SELECT LAST_INSERT_ID() id')[0]['id'];
        // Insert items + stock movements (dispatch)
        $sm = new StockMovement();
        foreach ($items as $it) {
            $pid = (int)($it['product_id'] ?? 0);
            $qty = max(0, (int)($it['quantity'] ?? 0));
            if ($pid <= 0 || $qty <= 0) continue;
            DB::execute('INSERT INTO seller_round_items(round_id,product_id,qty_assigned,qty_returned) VALUES(:r,:p,:qa,0)', [':r' => $rid, ':p' => $pid, ':qa' => $qty]);
            // Stock OUT from depot (dispatch to seller)
            $sm->move($depotId, $pid, 'out', $qty, date('Y-m-d H:i:s'), null, 'dispatch:user:' . $sellerId);
            Stock::adjust($depotId, $pid, 'out', $qty);
        }
        try {
            audit_log((int)$u['id'], 'add', 'seller_rounds', $rid, $path, 'POST', ['depot_id' => $depotId, 'user_id' => $sellerId]);
        } catch (\Throwable $e) {
        }
        echo json_encode(['created' => true, 'round_id' => $rid]);
        exit;
    }
    // Close seller round (returns + cash turned in)
    if (preg_match('#^/api/v1/seller-rounds/(\d+)$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'PATCH') {
        $auth = requireAuth();
        ensure_seller_rounds_tables();
        $rid = (int)$m[1];
        $round = DB::query('SELECT id,depot_id,user_id,status,assigned_at,closed_at FROM seller_rounds WHERE id=:id LIMIT 1', [':id' => $rid])[0] ?? null;
        if (!$round) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            exit;
        }
        // Authorization: admin/gerant or the seller himself
        if (!in_array(($auth['role'] ?? ''), ['admin', 'gerant'], true) && (int)$auth['id'] !== (int)$round['user_id']) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }
        if (($round['status'] ?? '') === 'closed') {
            http_response_code(409);
            echo json_encode(['error' => 'Already closed']);
            exit;
        }
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $returns = $data['returns'] ?? [];
        $cash = (int)($data['cash_turned_in'] ?? 0);
        $notes = $data['notes'] ?? null;
        // Apply returns with strict cap based on assigned-previously-returned -> stock IN only for applied delta
        $sm = new StockMovement();
        $appliedMeta = [];
        foreach ($returns as $it) {
            $pid = (int)($it['product_id'] ?? 0);
            $req = max(0, (int)($it['quantity'] ?? 0));
            if ($pid <= 0 || $req <= 0) continue;
            $row = DB::query('SELECT qty_assigned, qty_returned FROM seller_round_items WHERE round_id=:r AND product_id=:p LIMIT 1', [':r' => $rid, ':p' => $pid])[0] ?? null;
            if (!$row) continue;
            $assigned = (int)$row['qty_assigned'];
            $returned = (int)$row['qty_returned'];
            $allowed = max(0, $assigned - $returned);
            $apply = min($req, $allowed);
            if ($apply <= 0) {
                $appliedMeta[] = ['product_id' => $pid, 'requested' => $req, 'applied' => 0, 'allowed' => $allowed];
                continue;
            }
            DB::execute('UPDATE seller_round_items SET qty_returned = qty_returned + :q WHERE round_id=:r AND product_id=:p', [':q' => $apply, ':r' => $rid, ':p' => $pid]);
            // Stock IN back to depot for applied part only
            $sm->move((int)$round['depot_id'], $pid, 'in', $apply, date('Y-m-d H:i:s'), null, 'return:user:' . (int)$round['user_id']);
            Stock::adjust((int)$round['depot_id'], $pid, 'in', $apply);
            if ($apply !== $req) {
                $appliedMeta[] = ['product_id' => $pid, 'requested' => $req, 'applied' => $apply, 'allowed' => $allowed];
            }
        }
        // After applying provided returns, enforce alignment: for each item, assigned - sold = returned
        $items = DB::query('SELECT product_id, qty_assigned, qty_returned FROM seller_round_items WHERE round_id=:r', [':r' => $rid]);
        $mismatches = [];
        foreach ($items as $ri) {
            $pid = (int)$ri['product_id'];
            $assigned = (int)$ri['qty_assigned'];
            $returnedNow = (int)$ri['qty_returned'];
            // Compute sold during the round window
            $soldQty = get_round_sold_qty($round, $pid);
            $expectedReturned = max(0, $assigned - $soldQty);
            if ($returnedNow !== $expectedReturned) {
                $mismatches[] = [
                    'product_id' => $pid,
                    'assigned' => $assigned,
                    'sold' => $soldQty,
                    'returned' => $returnedNow,
                    'expected_returned' => $expectedReturned
                ];
            }
        }
        if (!empty($mismatches)) {
            http_response_code(422);
            echo json_encode(['error' => 'ROUND_NOT_BALANCED', 'details' => $mismatches, 'hint' => 'Ajustez les retours pour que Assigné - Vendu = Retourné.']);
            exit;
        }
        // Financial consistency checks in round window
        $paramsWin = [
            ':u' => (int)$round['user_id'],
            ':d' => (int)$round['depot_id'],
            ':from' => $round['assigned_at'],
        ];
        $whereTo = '';
        if (!empty($round['closed_at'])) {
            $whereTo = ' AND s.sold_at <= :to';
            $paramsWin[':to'] = $round['closed_at'];
        }
        // Sum amount by items vs sales
        $sumItems = DB::query('SELECT COALESCE(SUM(si.quantity * si.unit_price),0) v FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE s.user_id=:u AND s.depot_id=:d AND s.sold_at >= :from' . $whereTo, $paramsWin)[0]['v'] ?? 0;
        $sumSales = DB::query('SELECT COALESCE(SUM(s.total_amount),0) v FROM sales s WHERE s.user_id=:u AND s.depot_id=:d AND s.sold_at >= :from' . $whereTo, $paramsWin)[0]['v'] ?? 0;
        if ((int)$sumItems !== (int)$sumSales) {
            http_response_code(422);
            echo json_encode(['error' => 'AMOUNTS_MISMATCH', 'details' => ['by_items' => (int)$sumItems, 'by_sales' => (int)$sumSales]]);
            exit;
        }
        // Payments sum vs cash turned in (if provided)
        $sumPayments = DB::query('SELECT COALESCE(SUM(sp.amount),0) v FROM sale_payments sp JOIN sales s ON s.id=sp.sale_id WHERE s.user_id=:u AND s.depot_id=:d AND s.sold_at >= :from' . $whereTo, $paramsWin)[0]['v'] ?? 0;
        if ($cash < 0) {
            http_response_code(422);
            echo json_encode(['error' => 'INVALID_CASH']);
            exit;
        }
        if ((int)$cash !== (int)$sumPayments) {
            http_response_code(422);
            echo json_encode(['error' => 'CASH_MISMATCH', 'details' => ['cash_turned_in' => (int)$cash, 'expected' => (int)$sumPayments]]);
            exit;
        }
        DB::execute('UPDATE seller_rounds SET status="closed", cash_turned_in=:c, notes=:n, closed_at=NOW() WHERE id=:id', [':c' => $cash, ':n' => $notes, ':id' => $rid]);
        try {
            audit_log((int)$auth['id'], 'modify', 'seller_rounds', $rid, $path, 'PATCH', ['cash' => $cash, 'returns' => $appliedMeta]);
        } catch (\Throwable $e) {
        }
        echo json_encode(['closed' => true, 'round_id' => $rid]);
        exit;
    }
    // List seller rounds
    if ($path === '/api/v1/seller-rounds' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $auth = requireAuth();
        ensure_seller_rounds_tables();
        $status = ($_GET['status'] ?? '');
        $userId = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int)$_GET['user_id'] : null;
        $where = [];
        $params = [];
        if (in_array($status, ['open', 'closed'], true)) {
            $where[] = 'sr.status=:st';
            $params[':st'] = $status;
        }
        if ($userId !== null) {
            $where[] = 'sr.user_id=:u';
            $params[':u'] = $userId;
        }
        // Access: admin sees all; gerant sees depot rounds; others see their own
        $role = (string)($auth['role'] ?? '');
        if (!in_array($role, ['admin'], true)) {
            if ($role === 'gerant' && !empty($auth['depot_id'])) {
                $where[] = 'sr.depot_id=:dep';
                $params[':dep'] = (int)$auth['depot_id'];
            } else {
                $where[] = 'sr.user_id=:me';
                $params[':me'] = (int)$auth['id'];
            }
        }
        $sql = 'SELECT sr.id,sr.depot_id,sr.user_id,sr.status,sr.cash_turned_in,sr.assigned_at,sr.closed_at,u.name AS user_name,d.name AS depot_name '
            . 'FROM seller_rounds sr LEFT JOIN users u ON u.id=sr.user_id LEFT JOIN depots d ON d.id=sr.depot_id';
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY sr.assigned_at DESC LIMIT 200';
        $rows = DB::query($sql, $params);
        // Optional: aggregate items brief
        $ids = array_column($rows, 'id');
        $itemsMap = [];
        if ($ids) {
            $in = implode(',', array_map('intval', $ids));
            $itRows = DB::query('SELECT i.round_id,i.product_id,p.name,i.qty_assigned,i.qty_returned FROM seller_round_items i JOIN products p ON p.id=i.product_id WHERE i.round_id IN (' . $in . ')');
            foreach ($itRows as $ir) {
                $itemsMap[(int)$ir['round_id']][] = $ir;
            }
        }
        foreach ($rows as &$r) {
            $r['items'] = $itemsMap[(int)$r['id']] ?? [];
        }
        echo json_encode($rows);
        exit;
    }
    // Add payment to a sale (recouvrement)
    if (preg_match('#^/api/v1/sales/(\d+)/payments$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $auth = requireAuth();
        requirePermission($auth, 'sales', 'edit');
        ensure_sale_payments_table();
        $sid = (int)$m[1];
        $sale = DB::query('SELECT id,total_amount,amount_paid FROM sales WHERE id=:id LIMIT 1', [':id' => $sid])[0] ?? null;
        if (!$sale) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            exit;
        }
        $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $amount = (int)($data['amount'] ?? 0);
        $method = $data['method'] ?? null;
        if ($amount <= 0) {
            http_response_code(422);
            echo json_encode(['error' => 'Montant invalide']);
            exit;
        }
        DB::execute('INSERT INTO sale_payments(sale_id,amount,method,user_id,paid_at) VALUES(:s,:a,:m,:u,NOW())', [':s' => $sid, ':a' => $amount, ':m' => $method, ':u' => (int)$auth['id']]);
        // Update sale aggregate + status
        DB::execute('UPDATE sales SET amount_paid = amount_paid + :a, status = CASE WHEN amount_paid + :a >= total_amount THEN "paid" ELSE "due" END, updated_at=NOW() WHERE id=:id', [':a' => $amount, ':id' => $sid]);
        try {
            audit_log((int)$auth['id'], 'add', 'sale_payments', $sid, $path, 'POST', ['amount' => $amount]);
        } catch (\Throwable $e) {
        }
        $row = DB::query('SELECT id,total_amount,amount_paid FROM sales WHERE id=:id', [':id' => $sid])[0] ?? null;
        echo json_encode(['ok' => true, 'sale' => $row]);
        exit;
    }
    // Dashboard export CSV
    if ($path === '/api/v1/dashboard/export' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $auth = requireAuth();
        requireUserCan($auth, 'dashboard', 'export');
        $role = (string)($auth['role'] ?? '');
        $uid = (int)($auth['id'] ?? 0);
        $userDepotId = (int)($auth['depot_id'] ?? 0);
        $days = (int)($_GET['days'] ?? 30);
        if (!in_array($days, [7, 30, 90], true)) $days = 30;
        $paramDepot = isset($_GET['depot_id']) ? (int)$_GET['depot_id'] : null;
        $threshold = (int)($_GET['threshold'] ?? 5);
        if (!in_array($threshold, [3, 5, 10], true)) $threshold = 5;

        // Scopes
        $salesWhere = [];
        $salesParams = [];
        if ($role === 'admin') {
            if ($paramDepot && $paramDepot > 0) {
                $salesWhere[] = 'depot_id = :dep';
                $salesParams[':dep'] = $paramDepot;
            }
        } elseif ($role === 'gerant' && $userDepotId > 0) {
            $salesWhere[] = 'depot_id = :dep';
            $salesParams[':dep'] = $userDepotId;
        } else {
            $salesWhere[] = 'user_id = :uid';
            $salesParams[':uid'] = $uid;
        }
        $salesScopeSql = $salesWhere ? (' WHERE ' . implode(' AND ', $salesWhere)) : '';

        $stockWhere = [];
        $stockParams = [];
        if ($role === 'gerant' && $userDepotId > 0) {
            $stockWhere[] = 's.depot_id = :dep';
            $stockParams[':dep'] = $userDepotId;
        }
        $stockScopeSql = $stockWhere ? (' WHERE ' . implode(' AND ', $stockWhere)) : '';

        // Ensure optional tables
        try {
            ensure_seller_rounds_tables();
        } catch (\Throwable $e) {
        }
        try {
            ensure_sale_payments_table();
        } catch (\Throwable $e) {
        }
        // Ensure cost_price column exists on products (for stock valuation)
        try {
            $col = DB::query('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="products" AND COLUMN_NAME="cost_price"');
            if (!$col) {
                DB::execute('ALTER TABLE products ADD COLUMN cost_price INT NOT NULL DEFAULT 0 AFTER unit_price');
            }
        } catch (\Throwable $e) {
        }
        // Ensure cost_price column exists on products (for stock valuation)
        try {
            $col = DB::query('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="products" AND COLUMN_NAME="cost_price"');
            if (!$col) {
                DB::execute('ALTER TABLE products ADD COLUMN cost_price INT NOT NULL DEFAULT 0 AFTER unit_price');
            }
        } catch (\Throwable $e) {
        }

        // Data
        $stockTotal = (int)(DB::query('SELECT COALESCE(SUM(quantity),0) qty FROM stocks s' . ($stockScopeSql ? $stockScopeSql : ''))[0]['qty'] ?? 0);
        $stockValuation = (int)(DB::query('SELECT COALESCE(SUM(s.quantity * p.cost_price),0) v FROM stocks s JOIN products p ON p.id=s.product_id' . ($stockScopeSql ? $stockScopeSql : ''), $stockParams)[0]['v'] ?? 0);
        $stockValuation = (int)(DB::query('SELECT COALESCE(SUM(s.quantity * p.cost_price),0) v FROM stocks s JOIN products p ON p.id=s.product_id' . ($stockScopeSql ? $stockScopeSql : ''), $stockParams)[0]['v'] ?? 0);
        $topBalances = DB::query('SELECT c.id, c.name, (SUM(s.total_amount) - SUM(s.amount_paid)) AS balance FROM sales s JOIN clients c ON c.id = s.client_id' . $salesScopeSql . ' GROUP BY c.id,c.name HAVING balance > 0 ORDER BY balance DESC LIMIT 5', $salesParams);
        $today = date('Y-m-d');
        $dailyDepot = $userDepotId > 0 ? $userDepotId : 1;
        $daily = ReportService::daily($dailyDepot, $today);
        $caToday = (int)(DB::query('SELECT COALESCE(SUM(total_amount),0) v FROM sales' . ($salesScopeSql ? $salesScopeSql . ' AND' : ' WHERE') . ' DATE(sold_at)=CURDATE()', $salesParams)[0]['v'] ?? 0);
        $salesToday = (int)(DB::query('SELECT COUNT(*) c FROM sales' . ($salesScopeSql ? $salesScopeSql . ' AND' : ' WHERE') . ' DATE(sold_at)=CURDATE()', $salesParams)[0]['c'] ?? 0);
        $activeClients30 = (int)(DB::query('SELECT COUNT(DISTINCT client_id) c FROM sales' . ($salesScopeSql ? $salesScopeSql . ' AND' : ' WHERE') . ' sold_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)', $salesParams)[0]['c'] ?? 0);
        $receivablesTotal = (int)(DB::query('SELECT COALESCE(SUM(total_amount - amount_paid),0) v FROM sales' . $salesScopeSql, $salesParams)[0]['v'] ?? 0);
        // Rounds open (scoped)
        $roundsWhere = ['sr.status = "open"'];
        $roundsParams = [];
        if ($role === 'admin') {
            if ($paramDepot && $paramDepot > 0) {
                $roundsWhere[] = 'sr.depot_id = :dep';
                $roundsParams[':dep'] = $paramDepot;
            }
        } elseif ($role === 'gerant' && $userDepotId > 0) {
            $roundsWhere[] = 'sr.depot_id = :dep';
            $roundsParams[':dep'] = $userDepotId;
        } else {
            $roundsWhere[] = 'sr.user_id = :uid';
            $roundsParams[':uid'] = $uid;
        }
        $roundsOpen = (int)(DB::query('SELECT COUNT(*) c FROM seller_rounds sr WHERE ' . implode(' AND ', $roundsWhere), $roundsParams)[0]['c'] ?? 0);
        // Cash today (closed today)
        $cashWhere = ['sr.status = "closed"', 'DATE(sr.closed_at) = CURDATE()'];
        $cashParams = [];
        if ($role === 'admin') {
            if ($paramDepot && $paramDepot > 0) {
                $cashWhere[] = 'sr.depot_id = :dep';
                $cashParams[':dep'] = $paramDepot;
            }
        } elseif ($role === 'gerant' && $userDepotId > 0) {
            $cashWhere[] = 'sr.depot_id = :dep';
            $cashParams[':dep'] = $userDepotId;
        } else {
            $cashWhere[] = 'sr.user_id = :uid';
            $cashParams[':uid'] = $uid;
        }
        $cashToday = (int)(DB::query('SELECT COALESCE(SUM(sr.cash_turned_in),0) v FROM seller_rounds sr WHERE ' . implode(' AND ', $cashWhere), $cashParams)[0]['v'] ?? 0);
        // Collections today
        $payWhere = ['DATE(sp.paid_at) = CURDATE()'];
        $payParams = $salesParams;
        if ($role === 'admin') {
            if ($paramDepot && $paramDepot > 0) {
                $payWhere[] = 's.depot_id = :dep';
            }
        } elseif ($role === 'gerant' && $userDepotId > 0) {
            $payWhere[] = 's.depot_id = :dep';
        } else {
            $payWhere[] = 's.user_id = :uid';
        }
        $collectionsToday = (int)(DB::query('SELECT COALESCE(SUM(sp.amount),0) v FROM sale_payments sp JOIN sales s ON s.id=sp.sale_id WHERE ' . implode(' AND ', $payWhere), $payParams)[0]['v'] ?? 0);
        $rowsSeries = DB::query('SELECT DATE(sold_at) d, SUM(total_amount) v FROM sales' . ($salesScopeSql ? $salesScopeSql . ' AND' : ' WHERE') . ' sold_at >= DATE_SUB(CURDATE(), INTERVAL ' . ($days - 1) . ' DAY) GROUP BY DATE(sold_at) ORDER BY d ASC', $salesParams);
        $topProducts = DB::query('SELECT p.name, SUM(si.subtotal) total FROM sale_items si JOIN sales s ON s.id=si.sale_id JOIN products p ON p.id=si.product_id' . ($salesScopeSql ? $salesScopeSql . ' AND' : ' WHERE') . ' s.sold_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY p.id,p.name ORDER BY total DESC LIMIT 10', $salesParams);
        $lowStock = DB::query('SELECT p.id, p.name, COALESCE(SUM(s.quantity),0) qty FROM products p LEFT JOIN stocks s ON s.product_id=p.id' . ($stockScopeSql ? $stockScopeSql : '') . ' GROUP BY p.id,p.name HAVING qty <= :th ORDER BY qty ASC, p.name ASC LIMIT 10', $stockParams + [':th' => $threshold]);
        $ordersStatus = DB::query('SELECT status, COUNT(*) c FROM orders GROUP BY status');
        $byUser = DB::query('SELECT u.id, u.name, SUM(s.total_amount) total FROM sales s JOIN users u ON u.id=s.user_id' . ($salesScopeSql ? $salesScopeSql . ' AND' : ' WHERE') . ' s.sold_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY u.id,u.name ORDER BY total DESC LIMIT 5', $salesParams);

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="dashboard.csv"');
        $out = fopen('php://output', 'w');
        // Filters
        fputcsv($out, ['Dashboard export']);
        fputcsv($out, ['Période (jours)', $days]);
        fputcsv($out, ['Dépôt ciblé', ($paramDepot && $role === 'admin') ? $paramDepot : ($role === 'gerant' ? $userDepotId : '—')]);
        fputcsv($out, ['Seuil alerte stock', $threshold]);
        fputcsv($out, []);

        // Quick stats
        fputcsv($out, ['Quick stats']);
        fputcsv($out, ['CA du jour', format_fcfa($caToday)]);
        fputcsv($out, ['Ventes du jour', $salesToday]);
        fputcsv($out, ['Clients actifs (30j)', $activeClients30]);
        fputcsv($out, ['Encours (créances)', format_fcfa($receivablesTotal)]);
        fputcsv($out, ['Stock total', $stockTotal]);
        fputcsv($out, ['Valorisation stock', format_fcfa($stockValuation)]);
        fputcsv($out, ['Tournées ouvertes', $roundsOpen]);
        fputcsv($out, ['Cash remis (auj.)', format_fcfa($cashToday)]);
        fputcsv($out, ['Recouvrement (auj.)', format_fcfa($collectionsToday)]);
        fputcsv($out, []);

        // Revenue series
        fputcsv($out, ['Revenus sur ' . $days . ' jours']);
        fputcsv($out, ['Date', 'Montant']);
        foreach ($rowsSeries as $r) fputcsv($out, [$r['d'], format_fcfa((int)$r['v'])]);
        fputcsv($out, []);

        // Top products
        fputcsv($out, ['Top produits (30j)']);
        fputcsv($out, ['Produit', 'Total']);
        foreach ($topProducts as $r) fputcsv($out, [$r['name'], format_fcfa((int)$r['total'])]);
        fputcsv($out, []);

        // Orders status
        fputcsv($out, ['Répartition commandes']);
        fputcsv($out, ['Statut', 'Nombre']);
        foreach ($ordersStatus as $r) fputcsv($out, [$r['status'], (int)$r['c']]);
        fputcsv($out, []);

        // Sales by user
        fputcsv($out, ['Ventes par utilisateur (30j)']);
        fputcsv($out, ['Utilisateur', 'CA']);
        foreach ($byUser as $r) fputcsv($out, [($r['name'] ?? ('#' . $r['id'])), format_fcfa((int)$r['total'])]);
        fputcsv($out, []);

        // Low stock
        fputcsv($out, ['Produits en alerte (<= ' . $threshold . ')']);
        fputcsv($out, ['Produit', 'Stock']);
        foreach ($lowStock as $r) fputcsv($out, [$r['name'], (int)$r['qty']]);
        fputcsv($out, []);

        // Top balances
        fputcsv($out, ['Top soldes clients']);
        fputcsv($out, ['Client', 'Solde']);
        foreach ($topBalances as $r) fputcsv($out, [$r['name'], format_fcfa((int)$r['balance'])]);
        fputcsv($out, []);

        // Daily sales table
        fputcsv($out, ['Ventes du jour (dépôt ' . $dailyDepot . ')']);
        fputcsv($out, ['Article', 'PU', 'Sorties', 'Retourné', 'Vendu', 'Montant']);
        foreach ($daily['rows'] as $row) {
            fputcsv($out, [$row['name'], format_fcfa((int)$row['unit_price']), (int)$row['sorties'], (int)$row['retourne'], (int)$row['vendu'], format_fcfa((int)$row['montant'])]);
        }
        fputcsv($out, ['TOTAL', '', '', '', '', format_fcfa((int)$daily['total_montant'])]);
        fclose($out);
        // Audit export
        try {
            audit_log((int)$auth['id'], 'export', 'dashboard', null, $path, 'GET', ['days' => $days, 'depot' => $paramDepot, 'threshold' => $threshold]);
        } catch (\Throwable $e) {
        }
        exit;
    }
    // Dashboard export PDF
    if ($path === '/api/v1/dashboard/export-pdf' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $auth = requireAuth();
        requireUserCan($auth, 'dashboard', 'export');
        $role = (string)($auth['role'] ?? '');
        $uid = (int)($auth['id'] ?? 0);
        $userDepotId = (int)($auth['depot_id'] ?? 0);
        $days = (int)($_GET['days'] ?? 30);
        if (!in_array($days, [7, 30, 90], true)) $days = 30;
        $paramDepot = isset($_GET['depot_id']) ? (int)$_GET['depot_id'] : null;
        $threshold = (int)($_GET['threshold'] ?? 5);
        if (!in_array($threshold, [3, 5, 10], true)) $threshold = 5;
        if (!class_exists('TCPDF')) {
            try {
                @include_once __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php';
            } catch (\Throwable $e) {
            }
        }
        if (!class_exists('TCPDF')) {
            http_response_code(500);
            echo 'TCPDF non installé. Installez avec: composer require tecnickcom/tcpdf';
            exit;
        }

        // Scopes
        $salesWhere = [];
        $salesParams = [];
        if ($role === 'admin') {
            if ($paramDepot && $paramDepot > 0) {
                $salesWhere[] = 'depot_id = :dep';
                $salesParams[':dep'] = $paramDepot;
            }
        } elseif ($role === 'gerant' && $userDepotId > 0) {
            $salesWhere[] = 'depot_id = :dep';
            $salesParams[':dep'] = $userDepotId;
        } else {
            $salesWhere[] = 'user_id = :uid';
            $salesParams[':uid'] = $uid;
        }
        $salesScopeSql = $salesWhere ? (' WHERE ' . implode(' AND ', $salesWhere)) : '';
        $stockWhere = [];
        $stockParams = [];
        if ($role === 'gerant' && $userDepotId > 0) {
            $stockWhere[] = 's.depot_id = :dep';
            $stockParams[':dep'] = $userDepotId;
        }
        $stockScopeSql = $stockWhere ? (' WHERE ' . implode(' AND ', $stockWhere)) : '';

        // Ensure optional tables
        try {
            ensure_seller_rounds_tables();
        } catch (\Throwable $e) {
        }
        try {
            ensure_sale_payments_table();
        } catch (\Throwable $e) {
        }

        // Data
        $stockTotal = (int)(DB::query('SELECT COALESCE(SUM(quantity),0) qty FROM stocks s' . ($stockScopeSql ? $stockScopeSql : ''))[0]['qty'] ?? 0);
        // Try to compute stock valuation; fall back to 0 if column missing
        $stockValuation = 0;
        try {
            $rowVal = DB::query('SELECT COALESCE(SUM(s.quantity * p.cost_price),0) v FROM stocks s JOIN products p ON p.id=s.product_id' . ($stockScopeSql ? $stockScopeSql : ''))[0] ?? null;
            if ($rowVal) $stockValuation = (int)$rowVal['v'];
        } catch (\Throwable $e) {
        }
        $topBalances = DB::query('SELECT c.id, c.name, (SUM(s.total_amount) - SUM(s.amount_paid)) AS balance FROM sales s JOIN clients c ON c.id = s.client_id' . $salesScopeSql . ' GROUP BY c.id,c.name HAVING balance > 0 ORDER BY balance DESC LIMIT 5', $salesParams);
        $today = date('Y-m-d');
        $dailyDepot = $userDepotId > 0 ? $userDepotId : 1;
        $daily = ReportService::daily($dailyDepot, $today);
        $caToday = (int)(DB::query('SELECT COALESCE(SUM(total_amount),0) v FROM sales' . ($salesScopeSql ? $salesScopeSql . ' AND' : ' WHERE') . ' DATE(sold_at)=CURDATE()', $salesParams)[0]['v'] ?? 0);
        $salesToday = (int)(DB::query('SELECT COUNT(*) c FROM sales' . ($salesScopeSql ? $salesScopeSql . ' AND' : ' WHERE') . ' DATE(sold_at)=CURDATE()', $salesParams)[0]['c'] ?? 0);
        $activeClients30 = (int)(DB::query('SELECT COUNT(DISTINCT client_id) c FROM sales' . ($salesScopeSql ? $salesScopeSql . ' AND' : ' WHERE') . ' sold_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)', $salesParams)[0]['c'] ?? 0);
        $receivablesTotal = (int)(DB::query('SELECT COALESCE(SUM(total_amount - amount_paid),0) v FROM sales' . $salesScopeSql, $salesParams)[0]['v'] ?? 0);
        // Rounds open (scoped)
        $roundsWhere = ['sr.status = "open"'];
        $roundsParams = [];
        if ($role === 'admin') {
            if ($paramDepot && $paramDepot > 0) {
                $roundsWhere[] = 'sr.depot_id = :dep';
                $roundsParams[':dep'] = $paramDepot;
            }
        } elseif ($role === 'gerant' && $userDepotId > 0) {
            $roundsWhere[] = 'sr.depot_id = :dep';
            $roundsParams[':dep'] = $userDepotId;
        } else {
            $roundsWhere[] = 'sr.user_id = :uid';
            $roundsParams[':uid'] = $uid;
        }
        $roundsOpen = (int)(DB::query('SELECT COUNT(*) c FROM seller_rounds sr WHERE ' . implode(' AND ', $roundsWhere), $roundsParams)[0]['c'] ?? 0);
        // Cash today (closed today)
        $cashWhere = ['sr.status = "closed"', 'DATE(sr.closed_at) = CURDATE()'];
        $cashParams = [];
        if ($role === 'admin') {
            if ($paramDepot && $paramDepot > 0) {
                $cashWhere[] = 'sr.depot_id = :dep';
                $cashParams[':dep'] = $paramDepot;
            }
        } elseif ($role === 'gerant' && $userDepotId > 0) {
            $cashWhere[] = 'sr.depot_id = :dep';
            $cashParams[':dep'] = $userDepotId;
        } else {
            $cashWhere[] = 'sr.user_id = :uid';
            $cashParams[':uid'] = $uid;
        }
        $cashToday = (int)(DB::query('SELECT COALESCE(SUM(sr.cash_turned_in),0) v FROM seller_rounds sr WHERE ' . implode(' AND ', $cashWhere), $cashParams)[0]['v'] ?? 0);
        // Collections today
        $payWhere = ['DATE(sp.paid_at) = CURDATE()'];
        $payParams = $salesParams;
        if ($role === 'admin') {
            if ($paramDepot && $paramDepot > 0) {
                $payWhere[] = 's.depot_id = :dep';
            }
        } elseif ($role === 'gerant' && $userDepotId > 0) {
            $payWhere[] = 's.depot_id = :dep';
        } else {
            $payWhere[] = 's.user_id = :uid';
        }
        $collectionsToday = (int)(DB::query('SELECT COALESCE(SUM(sp.amount),0) v FROM sale_payments sp JOIN sales s ON s.id=sp.sale_id WHERE ' . implode(' AND ', $payWhere), $payParams)[0]['v'] ?? 0);
        $rowsSeries = DB::query('SELECT DATE(sold_at) d, SUM(total_amount) v FROM sales' . ($salesScopeSql ? $salesScopeSql . ' AND' : ' WHERE') . ' sold_at >= DATE_SUB(CURDATE(), INTERVAL ' . ($days - 1) . ' DAY) GROUP BY DATE(sold_at) ORDER BY d ASC', $salesParams);
        $topProducts = DB::query('SELECT p.name, SUM(si.subtotal) total FROM sale_items si JOIN sales s ON s.id=si.sale_id JOIN products p ON p.id=si.product_id' . ($salesScopeSql ? $salesScopeSql . ' AND' : ' WHERE') . ' s.sold_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY p.id,p.name ORDER BY total DESC LIMIT 10', $salesParams);
        $lowStock = DB::query('SELECT p.id, p.name, COALESCE(SUM(s.quantity),0) qty FROM products p LEFT JOIN stocks s ON s.product_id=p.id' . ($stockScopeSql ? $stockScopeSql : '') . ' GROUP BY p.id,p.name HAVING qty <= :th ORDER BY qty ASC, p.name ASC LIMIT 10', $stockParams + [':th' => $threshold]);
        $ordersStatus = DB::query('SELECT status, COUNT(*) c FROM orders GROUP BY status');
        $byUser = DB::query('SELECT u.id, u.name, SUM(s.total_amount) total FROM sales s JOIN users u ON u.id=s.user_id' . ($salesScopeSql ? $salesScopeSql . ' AND' : ' WHERE') . ' s.sold_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY u.id,u.name ORDER BY total DESC LIMIT 5', $salesParams);

        // PDF
        $pdf = new \TCPDF('P', 'mm', 'A4');
        $pdf->SetCreator('Hill Stock');
        $pdf->SetAuthor('Hill Stock');
        $pdf->SetTitle('Dashboard');
        $pdf->AddPage();
        $html = '<h2 style="margin:0 0 6px">Tableau de bord</h2>';
        $html .= '<div style="font-size:10px;color:#666">Généré le ' . htmlspecialchars(date('Y-m-d H:i')) . ' — Période: ' . (int)$days . 'j — Seuil: ' . (int)$threshold . '</div>';
        $html .= '<br />';
        // Quick stats
        $html .= '<h4>Indicateurs</h4><table border="1" cellpadding="4"><tbody>'
            . '<tr><td>CA du jour</td><td align="right">' . htmlspecialchars(format_fcfa($caToday)) . '</td></tr>'
            . '<tr><td>Ventes du jour</td><td align="right">' . (int)$salesToday . '</td></tr>'
            . '<tr><td>Clients actifs (30j)</td><td align="right">' . (int)$activeClients30 . '</td></tr>'
            . '<tr><td>Encours (créances)</td><td align="right">' . htmlspecialchars(format_fcfa($receivablesTotal)) . '</td></tr>'
            . '<tr><td>Stock total</td><td align="right">' . (int)$stockTotal . '</td></tr>'
            . '<tr><td>Valorisation stock</td><td align="right">' . htmlspecialchars(format_fcfa($stockValuation)) . '</td></tr>'
            . '<tr><td>Tournées ouvertes</td><td align="right">' . (int)$roundsOpen . '</td></tr>'
            . '<tr><td>Cash remis (auj.)</td><td align="right">' . htmlspecialchars(format_fcfa($cashToday)) . '</td></tr>'
            . '<tr><td>Recouvrement (auj.)</td><td align="right">' . htmlspecialchars(format_fcfa($collectionsToday)) . '</td></tr>'
            . '</tbody></table><br />';
        // Revenus série
        $html .= '<h4>Revenus (' . (int)$days . ' jours)</h4><table border="1" cellpadding="4"><thead><tr><th>Date</th><th>Montant</th></tr></thead><tbody>';
        foreach ($rowsSeries as $r) {
            $html .= '<tr><td>' . htmlspecialchars($r['d']) . '</td><td align="right">' . htmlspecialchars(format_fcfa((int)$r['v'])) . '</td></tr>';
        }
        $html .= '</tbody></table><br />';
        // Top produits
        $html .= '<h4>Top produits (30j)</h4><table border="1" cellpadding="4"><thead><tr><th>Produit</th><th>Total</th></tr></thead><tbody>';
        foreach ($topProducts as $r) {
            $html .= '<tr><td>' . htmlspecialchars($r['name']) . '</td><td align="right">' . htmlspecialchars(format_fcfa((int)$r['total'])) . '</td></tr>';
        }
        $html .= '</tbody></table><br />';
        // Commandes
        $html .= '<h4>Répartition commandes</h4><table border="1" cellpadding="4"><thead><tr><th>Statut</th><th>Nombre</th></tr></thead><tbody>';
        foreach ($ordersStatus as $r) {
            $html .= '<tr><td>' . htmlspecialchars($r['status']) . '</td><td align="right">' . (int)$r['c'] . '</td></tr>';
        }
        $html .= '</tbody></table><br />';
        // Par utilisateur
        $html .= '<h4>Ventes par utilisateur (30j)</h4><table border="1" cellpadding="4"><thead><tr><th>Utilisateur</th><th>CA</th></tr></thead><tbody>';
        foreach ($byUser as $r) {
            $html .= '<tr><td>' . htmlspecialchars(($r['name'] ?? ('#' . $r['id']))) . '</td><td align="right">' . htmlspecialchars(format_fcfa((int)$r['total'])) . '</td></tr>';
        }
        $html .= '</tbody></table><br />';
        // Low stock
        $html .= '<h4>Produits en alerte (≤ ' . (int)$threshold . ')</h4><table border="1" cellpadding="4"><thead><tr><th>Produit</th><th>Stock</th></tr></thead><tbody>';
        foreach ($lowStock as $r) {
            $html .= '<tr><td>' . htmlspecialchars($r['name']) . '</td><td align="right">' . (int)$r['qty'] . '</td></tr>';
        }
        $html .= '</tbody></table><br />';
        // Soldes clients
        $html .= '<h4>Top soldes clients</h4><table border="1" cellpadding="4"><thead><tr><th>Client</th><th>Solde</th></tr></thead><tbody>';
        foreach ($topBalances as $r) {
            $html .= '<tr><td>' . htmlspecialchars($r['name']) . '</td><td align="right">' . htmlspecialchars(format_fcfa((int)$r['balance'])) . '</td></tr>';
        }
        $html .= '</tbody></table><br />';
        // Ventes du jour
        $html .= '<h4>Ventes du jour (dépôt ' . (int)$dailyDepot . ')</h4><table border="1" cellpadding="4"><thead><tr><th>Article</th><th>PU</th><th>Sorties</th><th>Retourné</th><th>Vendu</th><th>Montant</th></tr></thead><tbody>';
        foreach ($daily['rows'] as $row) {
            $html .= '<tr><td>' . htmlspecialchars($row['name']) . '</td><td align="right">' . htmlspecialchars(format_fcfa((int)$row['unit_price'])) . '</td><td align="right">' . (int)$row['sorties'] . '</td><td align="right">' . (int)$row['retourne'] . '</td><td align="right">' . (int)$row['vendu'] . '</td><td align="right">' . htmlspecialchars(format_fcfa((int)$row['montant'])) . '</td></tr>';
        }
        $html .= '<tr><th colspan="5" align="right">Total</th><th align="right">' . htmlspecialchars(format_fcfa((int)$daily['total_montant'])) . '</th></tr>';
        $html .= '</tbody></table>';
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Output('dashboard.pdf', 'I');
        try {
            audit_log((int)$auth['id'], 'export', 'dashboard', null, $path, 'GET', ['days' => $days, 'depot' => $paramDepot, 'threshold' => $threshold]);
        } catch (\Throwable $e) {
        }
        exit;
    }
    // Permissions: current user info (for nav / frontend gating)
    if ($path === '/api/v1/auth/me' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $auth = requireAuth();
        $effective = userEffectivePermissions($auth);
        echo json_encode([
            'id' => (int)$auth['id'],
            'name' => $auth['name'] ?? null,
            'role' => $auth['role'] ?? null,
            'permissions' => $effective
        ]);
        exit;
    }
    // List all permission entities/actions available
    if ($path === '/api/v1/permissions/entities' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $auth = requireAuth();
        requireUserCan($auth, 'permissions', 'view');
        ensure_permissions_table();
        $entities = [
            'dashboard' => ['view', 'export'],
            'finance_stock' => ['view', 'export'],
            'stocks' => ['view', 'edit', 'delete'],
            'transfers' => ['view', 'edit', 'delete'],
            'orders' => ['view', 'edit', 'delete'],
            'products' => ['view', 'edit', 'delete'],
            'clients' => ['view', 'edit', 'delete'],
            'collections' => ['view', 'edit', 'delete'],
            'seller_rounds' => ['view', 'edit', 'delete'],
            'reports' => ['view', 'export'],
            'audit' => ['view'],
            'permissions' => ['view', 'edit']
        ];
        echo json_encode(['entities' => $entities]);
        exit;
    }
    // Users list (minimal) for permissions UI
    if ($path === '/api/v1/users' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $auth = requireAuth();
        if (!userCan($auth, 'users', 'view')) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }
        $limit = (int)($_GET['limit'] ?? 200);
        if ($limit < 1 || $limit > 1000) $limit = 200;
        $rows = DB::query('SELECT id,name,role FROM users ORDER BY name ASC LIMIT ' . $limit);
        echo json_encode(['users' => $rows]);
        exit;
    }
    // Get permissions for a specific user
    if ($path === '/api/v1/permissions/user' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $auth = requireAuth();
        requireUserCan($auth, 'permissions', 'view');
        ensure_permissions_table();
        $uid = (int)($_GET['user_id'] ?? 0);
        if ($uid <= 0) {
            http_response_code(422);
            echo json_encode(['error' => 'user_id requis']);
            exit;
        }
        $target = DB::query('SELECT id,name,role FROM users WHERE id=:id LIMIT 1', [":id" => $uid])[0] ?? null;
        if (!$target) {
            http_response_code(404);
            echo json_encode(['error' => 'User not found']);
            exit;
        }
        $explicit = loadExplicitPermissions($uid);
        $effective = mergeRoleDefaults((string)($target['role'] ?? ''), $explicit);
        echo json_encode(['user' => $target, 'explicit' => $explicit, 'effective' => $effective]);
        exit;
    }
    // Update permissions for a user (bulk)
    if ($path === '/api/v1/permissions/user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $auth = requireAuth();
        requireUserCan($auth, 'permissions', 'edit');
        ensure_permissions_table();
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!$payload) {
            http_response_code(400);
            echo json_encode(['error' => 'JSON invalide']);
            exit;
        }
        $uid = (int)($payload['user_id'] ?? 0);
        $changes = $payload['changes'] ?? [];
        if ($uid <= 0 || !is_array($changes)) {
            http_response_code(422);
            echo json_encode(['error' => 'Paramètres invalides']);
            exit;
        }
        $exists = DB::query('SELECT id, role FROM users WHERE id=:id LIMIT 1', [":id" => $uid])[0] ?? null;
        if (!$exists) {
            http_response_code(404);
            echo json_encode(['error' => 'User not found']);
            exit;
        }
        try {
            foreach ($changes as $c) {
                $entity = trim($c['entity'] ?? '');
                $action = trim($c['action'] ?? '');
                $allowed = !empty($c['allowed']) ? 1 : 0;
                if ($entity === '' || $action === '') continue;
                // Backticks to avoid reserved word conflicts
                DB::execute('REPLACE INTO `user_permissions`(`user_id`,`entity`,`action`,`allowed`) VALUES(:u,:e,:a,:al)', [
                    ':u' => $uid,
                    ':e' => $entity,
                    ':a' => $action,
                    ':al' => $allowed
                ]);
            }
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Erreur sauvegarde', 'details' => $e->getMessage()]);
            exit;
        }
        $explicit = loadExplicitPermissions($uid);
        $effective = mergeRoleDefaults((string)($exists['role'] ?? ''), $explicit);
        // Audit
        try {
            audit_log((int)$auth['id'], 'edit', 'permissions', $uid, $path, 'POST', $changes);
        } catch (\Throwable $e) {
        }
        echo json_encode(['ok' => true, 'explicit' => $explicit, 'effective' => $effective]);
        exit;
    }
    // API fallback 404
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
    exit;
}

// Web pages
if ($path === '/' || $path === '/dashboard') {
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/login');
        exit;
    }
    // Enforce permission dashboard:view
    $uid = (int)$_SESSION['user_id'];
    $urow = DB::query('SELECT * FROM users WHERE id=:id LIMIT 1', [':id' => $uid])[0] ?? null;
    if (!$urow || !userCan($urow, 'dashboard', 'view')) {
        http_response_code(403);
        echo 'Accès refusé';
        exit;
    }
    try {
        audit_log($uid, 'view', 'dashboard', null, $path, 'GET');
    } catch (\Throwable $e) {
    }
    include __DIR__ . '/../views/layout/header.php';
    include __DIR__ . '/../views/dashboard.php';
    include __DIR__ . '/../views/layout/footer.php';
    exit;
}
// Permissions admin page
if ($path === '/permissions') {
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/login');
        exit;
    }
    $uid = (int)$_SESSION['user_id'];
    $urow = DB::query('SELECT id,role FROM users WHERE id=:id LIMIT 1', [":id" => $uid])[0] ?? null;
    $canView = false;
    if ($urow && ($urow['role'] === 'admin')) $canView = true;
    else {
        $perms = userEffectivePermissions($urow ?: ['id' => $uid, 'role' => null]);
        $canView = !empty($perms['permissions']['view']);
    }
    if (!$canView) {
        http_response_code(403);
        echo 'Accès refusé';
        exit;
    }
    include __DIR__ . '/../views/permissions.php';
    exit;
}

if ($path === '/login') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!\App\Support\Security::verifyCsrf($_POST['_token'] ?? '')) {
            http_response_code(419);
            echo 'Jeton CSRF invalide';
            exit;
        }
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';
        $uModel = new User();
        $user = $uModel->findByEmail($email);
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            // Générer un token API et le pousser côté navigateur via cookie HttpOnly=false (simplifié)
            $token = (new User())->createToken((int)$user['id']);
            setcookie('api_token', $token, time() + 3600 * 24 * 7, '/');
            $redirBase = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
            header('Location: ' . ($redirBase ?: '/'));
            exit;
        }
        include __DIR__ . '/../views/login.php';
        exit;
    } else {
        include __DIR__ . '/../views/login.php';
        exit;
    }
}

if ($path === '/logout') {
    session_destroy();
    $redirBase = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    header('Location: ' . $redirBase . '/login');
    exit;
}

if ($path === '/depots/map') {
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/login');
        exit;
    }
    try {
        audit_log((int)$_SESSION['user_id'], 'view', 'depots_map', null, $path, 'GET');
    } catch (\Throwable $e) {
    }
    include __DIR__ . '/../views/layout/header.php';
    include __DIR__ . '/../views/depots_map.php';
    include __DIR__ . '/../views/layout/footer.php';
    exit;
}

// Admin pages (simple CRUD)
if ($path === '/products') {
    if (!empty($_SESSION['user_id'])) {
        $uid = (int)$_SESSION['user_id'];
        $u = DB::query('SELECT * FROM users WHERE id=:id LIMIT 1', [':id' => $uid])[0] ?? null;
        if (!$u || !userCan($u, 'products', 'view')) {
            http_response_code(403);
            echo 'Accès refusé';
            exit;
        }
    }
    try {
        if (!empty($_SESSION['user_id'])) audit_log((int)$_SESSION['user_id'], 'view', 'products', null, $path, 'GET');
    } catch (\Throwable $e) {
    }
    include __DIR__ . '/../views/layout/header.php';
    include __DIR__ . '/../views/products.php';
    include __DIR__ . '/../views/layout/footer.php';
    exit;
}
if ($path === '/products/new') {
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/login');
        exit;
    }
    $uid = (int)$_SESSION['user_id'];
    $u = DB::query('SELECT * FROM users WHERE id=:id LIMIT 1', [':id' => $uid])[0] ?? null;
    if (!$u || !userCan($u, 'products', 'edit')) {
        http_response_code(403);
        echo 'Accès refusé';
        exit;
    }
    try {
        if (!empty($_SESSION['user_id'])) audit_log((int)$_SESSION['user_id'], 'view', 'products_new', null, $path, 'GET');
    } catch (\Throwable $e) {
    }
    include __DIR__ . '/../views/layout/header.php';
    include __DIR__ . '/../views/products_new.php';
    include __DIR__ . '/../views/layout/footer.php';
    exit;
}
if ($path === '/products/edit') {
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/login');
        exit;
    }
    $uid = (int)$_SESSION['user_id'];
    $u = DB::query('SELECT * FROM users WHERE id=:id LIMIT 1', [':id' => $uid])[0] ?? null;
    if (!$u || !userCan($u, 'products', 'edit')) {
        http_response_code(403);
        echo 'Accès refusé';
        exit;
    }
    try {
        if (!empty($_SESSION['user_id'])) audit_log((int)$_SESSION['user_id'], 'view', 'products_edit', null, $path, 'GET');
    } catch (\Throwable $e) {
    }
    include __DIR__ . '/../views/layout/header.php';
    include __DIR__ . '/../views/products_edit.php';
    include __DIR__ . '/../views/layout/footer.php';
    exit;
}
if ($path === '/products/view') {
    try {
        if (!empty($_SESSION['user_id'])) audit_log((int)$_SESSION['user_id'], 'view', 'product_view', null, $path, 'GET');
    } catch (\Throwable $e) {
    }
    include __DIR__ . '/../views/layout/header.php';
    include __DIR__ . '/../views/product_view.php';
    include __DIR__ . '/../views/layout/footer.php';
    exit;
}
if ($path === '/clients') {
    if (!empty($_SESSION['user_id'])) {
        $uid = (int)$_SESSION['user_id'];
        $u = DB::query('SELECT * FROM users WHERE id=:id LIMIT 1', [':id' => $uid])[0] ?? null;
        if (!$u || !userCan($u, 'clients', 'view')) {
            http_response_code(403);
            echo 'Accès refusé';
            exit;
        }
    }
    try {
        if (!empty($_SESSION['user_id'])) audit_log((int)$_SESSION['user_id'], 'view', 'clients', null, $path, 'GET');
    } catch (\Throwable $e) {
    }
    include __DIR__ . '/../views/layout/header.php';
    include __DIR__ . '/../views/clients.php';
    include __DIR__ . '/../views/layout/footer.php';
    exit;
}
if ($path === '/clients/new') {
    try {
        if (!empty($_SESSION['user_id'])) audit_log((int)$_SESSION['user_id'], 'view', 'clients_new', null, $path, 'GET');
    } catch (\Throwable $e) {
    }
    include __DIR__ . '/../views/layout/header.php';
    include __DIR__ . '/../views/clients_form.php';
    include __DIR__ . '/../views/layout/footer.php';
    exit;
}
if ($path === '/clients/edit') {
    try {
        if (!empty($_SESSION['user_id'])) audit_log((int)$_SESSION['user_id'], 'view', 'clients_edit', null, $path, 'GET');
    } catch (\Throwable $e) {
    }
    include __DIR__ . '/../views/layout/header.php';
    include __DIR__ . '/../views/clients_form.php';
    include __DIR__ . '/../views/layout/footer.php';
    exit;
}
if ($path === '/users') {
    // Permission: users.view
    if (!empty($_SESSION['user_id'])) {
        $uid = (int)$_SESSION['user_id'];
        $u = DB::query('SELECT * FROM users WHERE id=:id LIMIT 1', [':id' => $uid])[0] ?? null;
        if (!$u || !can($u, 'users', 'view')) {
            http_response_code(403);
            echo 'Accès refusé';
            exit;
        }
    }
    try {
        if (!empty($_SESSION['user_id'])) audit_log((int)$_SESSION['user_id'], 'view', 'users', null, $path, 'GET');
    } catch (\Throwable $e) {
    }
    include __DIR__ . '/../views/layout/header.php';
    include __DIR__ . '/../views/users.php';
    include __DIR__ . '/../views/layout/footer.php';
    exit;
}
if ($path === '/users/new') {
    if (!empty($_SESSION['user_id'])) {
        $uid = (int)$_SESSION['user_id'];
        $u = DB::query('SELECT * FROM users WHERE id=:id LIMIT 1', [':id' => $uid])[0] ?? null;
        if (!$u || !can($u, 'users', 'edit')) {
            http_response_code(403);
            echo 'Accès refusé';
            exit;
        }
    }
    try {
        if (!empty($_SESSION['user_id'])) audit_log((int)$_SESSION['user_id'], 'view', 'users_new', null, $path, 'GET');
    } catch (\Throwable $e) {
    }
    include __DIR__ . '/../views/layout/header.php';
    include __DIR__ . '/../views/users_form.php';
    include __DIR__ . '/../views/layout/footer.php';
    exit;
}
if ($path === '/users/edit') {
    if (!empty($_SESSION['user_id'])) {
        $uid = (int)$_SESSION['user_id'];
        $u = DB::query('SELECT * FROM users WHERE id=:id LIMIT 1', [':id' => $uid])[0] ?? null;
        if (!$u || !can($u, 'users', 'edit')) {
            http_response_code(403);
            echo 'Accès refusé';
            exit;
        }
    }
    try {
        if (!empty($_SESSION['user_id'])) audit_log((int)$_SESSION['user_id'], 'view', 'users_edit', null, $path, 'GET');
    } catch (\Throwable $e) {
    }
    include __DIR__ . '/../views/layout/header.php';
    include __DIR__ . '/../views/users_form.php';
    include __DIR__ . '/../views/layout/footer.php';
    exit;
}
if ($path === '/depots') {
    if (!empty($_SESSION['user_id'])) {
        $uid = (int)$_SESSION['user_id'];
        $u = DB::query('SELECT * FROM users WHERE id=:id LIMIT 1', [':id' => $uid])[0] ?? null;
        if (!$u || !userCan($u, 'depots', 'view')) {
            http_response_code(403);
            echo 'Accès refusé';
            exit;
        }
    }
    try {
        if (!empty($_SESSION['user_id'])) audit_log((int)$_SESSION['user_id'], 'view', 'depots', null, $path, 'GET');
    } catch (\Throwable $e) {
    }
    include __DIR__ . '/../views/layout/header.php';
    include __DIR__ . '/../views/depots.php';
    include __DIR__ . '/../views/layout/footer.php';
    exit;
}
if ($path === '/depots/new') {
    try {
        if (!empty($_SESSION['user_id'])) audit_log((int)$_SESSION['user_id'], 'view', 'depots_new', null, $path, 'GET');
    } catch (\Throwable $e) {
    }
    include __DIR__ . '/../views/layout/header.php';
    include __DIR__ . '/../views/depots_new.php';
    include __DIR__ . '/../views/layout/footer.php';
    exit;
}
if ($path === '/depots/edit') {
    try {
        if (!empty($_SESSION['user_id'])) audit_log((int)$_SESSION['user_id'], 'view', 'depots_edit', null, $path, 'GET');
    } catch (\Throwable $e) {
    }
    include __DIR__ . '/../views/layout/header.php';
    include __DIR__ . '/../views/depots_edit.php';
    include __DIR__ . '/../views/layout/footer.php';
    exit;
}
if ($path === '/orders') {
    if (!empty($_SESSION['user_id'])) {
        $uid = (int)$_SESSION['user_id'];
        $u = DB::query('SELECT * FROM users WHERE id=:id LIMIT 1', [':id' => $uid])[0] ?? null;
        if (!$u || !userCan($u, 'orders', 'view')) {
            http_response_code(403);
            echo 'Accès refusé';
            exit;
        }
    }
    try {
        if (!empty($_SESSION['user_id'])) audit_log((int)$_SESSION['user_id'], 'view', 'orders', null, $path, 'GET');
    } catch (\Throwable $e) {
    }
    include __DIR__ . '/../views/layout/header.php';
    include __DIR__ . '/../views/orders.php';
    include __DIR__ . '/../views/layout/footer.php';
    exit;
}
// Orders new form
if ($path === '/orders/new') {
    try {
        if (!empty($_SESSION['user_id'])) audit_log((int)$_SESSION['user_id'], 'view', 'orders_new', null, $path, 'GET');
    } catch (\Throwable $e) {
    }
    include __DIR__ . '/../views/layout/header.php';
    include __DIR__ . '/../views/orders_form.php';
    include __DIR__ . '/../views/layout/footer.php';
    exit;
}
// Order view/export
if ($path === '/orders/export') {
    // export one order as PDF via TCPDF
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        http_response_code(422);
        echo 'ID manquant';
        exit;
    }
    $ord = DB::query('SELECT id,reference,supplier,status,total_amount,ordered_at,created_at FROM orders WHERE id=:id LIMIT 1', [':id' => $id])[0] ?? null;
    if (!$ord) {
        http_response_code(404);
        echo 'Commande introuvable';
        exit;
    }
    $items = DB::query('SELECT oi.product_id, p.name AS product_name, oi.initial_quantity, oi.quantity, oi.unit_cost, oi.subtotal FROM order_items oi JOIN products p ON p.id=oi.product_id WHERE oi.order_id=:o', [':o' => $id]);
    if (!class_exists('TCPDF')) {
        // Attempt to load if installed
        try {
            @include_once __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php';
        } catch (\Throwable $e) {
        }
    }
    if (!class_exists('TCPDF')) {
        http_response_code(500);
        echo 'TCPDF non installé. Installez avec: composer require tecnickcom/tcpdf';
        exit;
    }
    // Audit export
    try {
        $actor = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : (apiUser()['id'] ?? null);
        audit_log($actor, 'export', 'orders', (int)$ord['id'], $path, 'GET', ['reference' => $ord['reference'] ?? null]);
    } catch (\Throwable $e) {
    }
    $pdf = new \TCPDF();
    $pdf->SetCreator('Hill Stock');
    $pdf->SetAuthor('Hill');
    $pdf->SetTitle('Bon de commande ' . $ord['reference']);
    $pdf->AddPage();
    $html = '<h1 style="font-size:18px;">Bon de commande ' . htmlspecialchars($ord['reference']) . '</h1>';
    $html .= '<div>Fournisseur: ' . htmlspecialchars((string)$ord['supplier']) . '</div>';
    $html .= '<div>Status: ' . htmlspecialchars((string)$ord['status']) . '</div>';
    $html .= '<div>Date: ' . htmlspecialchars((string)$ord['ordered_at']) . '</div>';
    $html .= '<br /><table border="1" cellpadding="6"><thead><tr><th>Produit</th><th>Qté commandée</th><th>Qté restante</th><th>PU</th><th>Sous-total</th></tr></thead><tbody>';
    foreach ($items as $it) {
        $html .= '<tr><td>' . htmlspecialchars($it['product_name']) . '</td><td align="right">' . (int)$it['initial_quantity'] . '</td><td align="right">' . (int)$it['quantity'] . '</td><td align="right">' . (int)$it['unit_cost'] . '</td><td align="right">' . (int)$it['subtotal'] . '</td></tr>';
    }
    $html .= '</tbody></table><br /><h3>Total: ' . (int)$ord['total_amount'] . '</h3>';
    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Output('bon_' . $ord['reference'] . '.pdf', 'I');
    exit;
}
// User card export (ID pro)
if ($path === '/users/export') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        http_response_code(422);
        echo 'ID manquant';
        exit;
    }
    // Assurer colonnes
    try {
        $cols = DB::query('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="users"');
        $havePhoto = false;
        $haveActive = false;
        foreach ($cols as $c) {
            if ($c['COLUMN_NAME'] === 'photo_path') $havePhoto = true;
            if ($c['COLUMN_NAME'] === 'active') $haveActive = true;
        }
        if (!$havePhoto) DB::execute('ALTER TABLE users ADD COLUMN photo_path VARCHAR(255) NULL AFTER permissions');
        if (!$haveActive) DB::execute('ALTER TABLE users ADD COLUMN active TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER photo_path');
    } catch (\Throwable $e) {
    }
    $usr = DB::query('SELECT id,name,email,role,depot_id,photo_path,active,created_at FROM users WHERE id=:id LIMIT 1', [":id" => $id])[0] ?? null;
    if (!$usr) {
        http_response_code(404);
        echo 'Utilisateur introuvable';
        exit;
    }
    $dep = null;
    if (!empty($usr['depot_id'])) {
        $dep = DB::query('SELECT id,name,code FROM depots WHERE id=:d', [":d" => (int)$usr['depot_id']])[0] ?? null;
    }
    if (!class_exists('TCPDF')) {
        try {
            @include_once __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php';
        } catch (\Throwable $e) {
        }
    }
    if (!class_exists('TCPDF')) {
        http_response_code(500);
        echo 'TCPDF non installé.';
        exit;
    }
    // Audit export
    try {
        $actor = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : (apiUser()['id'] ?? null);
        audit_log($actor, 'export', 'users', (int)$usr['id'], $path, 'GET');
    } catch (\Throwable $e) {
    }
    $pdf = new TCPDF('P', 'mm', 'A4');
    $pdf->SetCreator('Hill Stock');
    $pdf->SetAuthor('Hill Stock');
    $pdf->SetTitle('Fiche utilisateur');
    $pdf->AddPage();
    $html = '<style>
    .card-id{border:2px solid #222;border-radius:14px;padding:16px;font-family:helvetica;max-width:380px;box-shadow:0 3px 8px rgba(0,0,0,.15);}
    .cid-header{display:flex;align-items:center;gap:12px;margin-bottom:10px;}
    .cid-photo{width:90px;height:110px;object-fit:cover;border:1px solid #555;border-radius:6px;background:#eee;}
    .cid-title{font-size:16px;font-weight:bold;letter-spacing:.7px;text-transform:uppercase;}
    .cid-row{margin:3px 0;font-size:11px;}
    .cid-label{color:#555;font-weight:bold;}
    .cid-badge{display:inline-block;padding:3px 8px;border-radius:12px;background:#0d6efd;color:#fff;font-size:10px;}
    .cid-inactive{background:#b02a37 !important;}
    .cid-footer{margin-top:10px;font-size:9px;color:#777;}
    .cid-topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;}
    .cid-logo{height:32px;}
    </style>';
    // Logo si présent (public/assets/img/logo.png)
    $logoPathFS = __DIR__ . '/assets/img/logo.png';
    $logoTag = is_file($logoPathFS) ? '<img class="cid-logo" src="/assets/img/logo.png" />' : '<div style="font-size:12px;font-weight:bold">HILL STOCK</div>';
    $photo = $usr['photo_path'] ?? '';
    $photoTag = '';
    if ($photo && preg_match('#^/#', $photo)) {
        $fs = __DIR__ . $photo; // photo path relative to public
        if (is_file($fs)) {
            // embed as image file
            $pdf->Image($fs, 20, 40, 30, 37, '', '', '', true); // also place separately for print quality
            $photoTag = '<img class="cid-photo" src="' . htmlspecialchars($photo) . '" />';
        } else {
            $photoTag = '<div class="cid-photo"></div>';
        }
    } else {
        $photoTag = '<div class="cid-photo"></div>';
    }
    $badgeClass = 'cid-badge' . ((int)$usr['active'] === 1 ? '' : ' cid-inactive');
    $activeLabel = (int)$usr['active'] === 1 ? 'ACTIF' : 'INACTIF';
    $html .= '<div class="card-id">'
        . '<div class="cid-topbar">' . $logoTag . '<div style="font-size:10px;color:#666">ID: ' . htmlspecialchars((string)$usr['id']) . '</div></div>'
        . '<div class="cid-header">' . $photoTag . '<div><div class="cid-title">IDENTIFICATION UTILISATEUR</div><div class="cid-row"><span class="' . $badgeClass . '">' . $activeLabel . '</span></div></div></div>'
        . '<div class="cid-row"><span class="cid-label">Nom:</span> ' . htmlspecialchars($usr['name'] ?? '') . '</div>'
        . '<div class="cid-row"><span class="cid-label">Email/Login:</span> ' . htmlspecialchars($usr['email'] ?? '') . '</div>'
        . '<div class="cid-row"><span class="cid-label">Rôle:</span> ' . htmlspecialchars($usr['role'] ?? '') . '</div>'
        . '<div class="cid-row"><span class="cid-label">Dépôt:</span> ' . htmlspecialchars($dep ? ($dep['name'] . ($dep['code'] ? ' (' . $dep['code'] . ')' : '')) : 'N/A') . '</div>'
        . '<div class="cid-row"><span class="cid-label">Créé le:</span> ' . htmlspecialchars(substr((string)$usr['created_at'], 0, 19)) . '</div>'
        . '<div class="cid-row"><span class="cid-label">Mot de passe:</span> (non récupérable, réinitialisation requise)</div>'
        . '<div class="cid-footer">Document généré automatiquement - Confidentialité requise.</div>'
        . '</div>';
    $pdf->writeHTML($html);
    // QR code: réel si librairie installée (chillerlan/php-qrcode), fallback simplifié sinon
    $qrData = 'HILLUSER:' . $usr['id'] . ';' . ($usr['email'] ?? '') . ';' . ($usr['role'] ?? '');
    if (class_exists('chillerlan\\QRCode\\QRCode') && class_exists('chillerlan\\QRCode\\QROptions')) {
        try {
            $opts = new chillerlan\QRCode\QROptions([
                'outputType' => chillerlan\QRCode\QRCode::OUTPUT_MARKUP_SVG,
                'eccLevel' => chillerlan\QRCode\QRCode::ECC_L,
                'scale' => 3,
                'addQuietzone' => true,
            ]);
            $qrSVG = (new chillerlan\QRCode\QRCode($opts))->render($qrData);
            $html .= '<div style="margin-top:12px">' . $qrSVG . '</div>';
        } catch (\Throwable $e) {
            $html .= '<div style="margin-top:12px;font-size:7px;color:#b00">QR erreur: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    } else {
        $hash = md5($qrData);
        $size = 21;
        $bitSeq = '';
        while (strlen($bitSeq) < $size * $size) {
            foreach (str_split($hash) as $ch) {
                $bitSeq .= (hexdec($ch) % 2) ? '1' : '0';
                if (strlen($bitSeq) >= $size * $size) break;
            }
        }
        $html .= '<div style="margin-top:12px"><table cellspacing="0" cellpadding="0" style="border:1px solid #333">';
        $idx = 0;
        for ($y = 0; $y < $size; $y++) {
            $html .= '<tr>';
            for ($x = 0; $x < $size; $x++) {
                $b = $bitSeq[$idx++] === '1';
                $html .= '<td style="width:3mm;height:3mm;background:' . ($b ? '#000' : '#fff') . '"></td>';
            }
            $html .= '</tr>';
        }
        $html .= '</table><div style="font-size:7px;color:#666;text-align:center">QR simplifié</div></div>';
    }
    $pdf->writeHTML($html);
    $pdf->Output('fiche_utilisateur_' . $id . '.pdf', 'I');
    exit;
}

// Stock transfers page (alias: /transfers and /transferts)
if ($path === '/transfers' || $path === '/transferts') {
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/login');
        exit;
    }
    $uid = (int)$_SESSION['user_id'];
    $urow = DB::query('SELECT * FROM users WHERE id=:id LIMIT 1', [':id' => $uid])[0] ?? null;
    if (!$urow || !userCan($urow, 'transfers', 'view')) {
        http_response_code(403);
        echo 'Accès refusé';
        exit;
    }
    include __DIR__ . '/../views/layout/header.php';
    include __DIR__ . '/../views/transfers.php';
    include __DIR__ . '/../views/layout/footer.php';
    exit;
}

// Stocks by depot page
if ($path === '/stocks') {
    if (!empty($_SESSION['user_id'])) {
        $uid = (int)$_SESSION['user_id'];
        $u = DB::query('SELECT * FROM users WHERE id=:id LIMIT 1', [':id' => $uid])[0] ?? null;
        if (!$u || !userCan($u, 'stocks', 'view')) {
            http_response_code(403);
            echo 'Accès refusé';
            exit;
        }
    }
    include __DIR__ . '/../views/layout/header.php';
    include __DIR__ . '/../views/stocks.php';
    include __DIR__ . '/../views/layout/footer.php';
    exit;
}

// Quick Sales page (Vente rapide livreur)
if ($path === '/sales-quick') {
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/login');
        exit;
    }
    // sales:view requis
    $uid = (int)$_SESSION['user_id'];
    $u = DB::query('SELECT * FROM users WHERE id=:id LIMIT 1', [':id' => $uid])[0] ?? null;
    if (!$u || !userCan($u, 'sales', 'view')) {
        http_response_code(403);
        echo 'Accès refusé';
        exit;
    }
    try {
        audit_log((int)$_SESSION['user_id'], 'view', 'sales_quick', null, $path, 'GET');
    } catch (\Throwable $e) {
    }
    include __DIR__ . '/../views/layout/header.php';
    include __DIR__ . '/../views/sales_quick.php';
    include __DIR__ . '/../views/layout/footer.php';
    exit;
}

// Sales list page
if ($path === '/sales') {
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/login');
        exit;
    }
    $uid = (int)$_SESSION['user_id'];
    $u = DB::query('SELECT * FROM users WHERE id=:id LIMIT 1', [':id' => $uid])[0] ?? null;
    if (!$u || !userCan($u, 'sales', 'view')) {
        http_response_code(403);
        echo 'Accès refusé';
        exit;
    }
    try {
        audit_log((int)$_SESSION['user_id'], 'view', 'sales', null, $path, 'GET');
    } catch (\Throwable $e) {
    }
    include __DIR__ . '/../views/layout/header.php';
    include __DIR__ . '/../views/sales.php';
    include __DIR__ . '/../views/layout/footer.php';
    exit;
}

// Finance & Stock page
if ($path === '/finance-stock') {
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/login');
        exit;
    }
    // finance_stock:view requis
    $uid = (int)$_SESSION['user_id'];
    $u = DB::query('SELECT * FROM users WHERE id=:id LIMIT 1', [':id' => $uid])[0] ?? null;
    if (!$u || !userCan($u, 'finance_stock', 'view')) {
        http_response_code(403);
        echo 'Accès refusé';
        exit;
    }
    try {
        audit_log((int)$_SESSION['user_id'], 'view', 'finance_stock', null, $path, 'GET');
    } catch (\Throwable $e) {
    }
    include __DIR__ . '/../views/layout/header.php';
    include __DIR__ . '/../views/finance_stock.php';
    include __DIR__ . '/../views/layout/footer.php';
    exit;
}

// Seller rounds page (Remises livreurs)
if ($path === '/seller-rounds') {
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/login');
        exit;
    }
    $uid = (int)$_SESSION['user_id'];
    $u = DB::query('SELECT * FROM users WHERE id=:id LIMIT 1', [':id' => $uid])[0] ?? null;
    if (!$u || !userCan($u, 'seller_rounds', 'view')) {
        http_response_code(403);
        echo 'Accès refusé';
        exit;
    }
    try {
        audit_log((int)$_SESSION['user_id'], 'view', 'seller_rounds', null, $path, 'GET');
    } catch (\Throwable $e) {
    }
    include __DIR__ . '/../views/layout/header.php';
    include __DIR__ . '/../views/seller_rounds.php';
    include __DIR__ . '/../views/layout/footer.php';
    exit;
}

// Collections page (Recouvrement)
if ($path === '/collections') {
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/login');
        exit;
    }
    $uid = (int)$_SESSION['user_id'];
    $u = DB::query('SELECT * FROM users WHERE id=:id LIMIT 1', [':id' => $uid])[0] ?? null;
    if (!$u || !userCan($u, 'collections', 'view')) {
        http_response_code(403);
        echo 'Accès refusé';
        exit;
    }
    try {
        audit_log((int)$_SESSION['user_id'], 'view', 'collections', null, $path, 'GET');
    } catch (\Throwable $e) {
    }
    include __DIR__ . '/../views/layout/header.php';
    include __DIR__ . '/../views/collections.php';
    include __DIR__ . '/../views/layout/footer.php';
    exit;
}

// Logs page (admin)
if ($path === '/logs') {
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/login');
        exit;
    }
    // simple guard in page: only admin can view
    $uid = (int)$_SESSION['user_id'];
    $urow = DB::query('SELECT role FROM users WHERE id=:id', [':id' => $uid])[0] ?? null;
    if (!$urow || ($urow['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo 'Accès refusé';
        exit;
    }
    try {
        audit_log($uid, 'view', 'audit_logs', null, $path, 'GET');
    } catch (\Throwable $e) {
    }
    include __DIR__ . '/../views/layout/header.php';
    include __DIR__ . '/../views/logs.php';
    include __DIR__ . '/../views/layout/footer.php';
    exit;
}

http_response_code(404);
echo 'Page introuvable';
