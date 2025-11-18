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
            $perms = json_decode((string)$u['permissions'], true) ?: [];
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
        ],
    ];
    // Merge defaults
    if (isset($defaults[$role])) {
        foreach ($defaults[$role] as $ent => $acts) {
            if (!isset($perms[$ent])) $perms[$ent] = $acts;
            else $perms[$ent] = array_merge($acts, $perms[$ent]);
        }
    }
    return $perms;
}

function can(array $u, string $entity, string $action): bool
{
    if (($u['role'] ?? '') === 'admin') return true;
    $perms = parsePermissions($u);
    if (isset($perms['*'][$action]) && $perms['*'][$action]) return true;
    return !empty($perms[$entity][$action]);
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
        echo json_encode(['token' => $token, 'user' => ['id' => $user['id'], 'name' => $user['name'], 'role' => $user['role']]]);
        exit;
    }
    // Depots listing with geo
    if ($path === '/api/v1/depots' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        requireAuth();
        $q = trim($_GET['q'] ?? '');
        if ($q !== '') {
            $like = '%' . $q . '%';
            $rows = DB::query('SELECT id,name,code,is_main,manager_user_id,manager_name,phone,address,latitude,longitude FROM depots WHERE name LIKE :q OR code LIKE :q OR manager_name LIKE :q OR address LIKE :q ORDER BY id DESC', [':q' => $like]);
        } else {
            $rows = DB::query('SELECT id,name,code,is_main,manager_user_id,manager_name,phone,address,latitude,longitude FROM depots ORDER BY id DESC');
        }
        echo json_encode($rows);
        exit;
    }
    // Get a single depot
    if (preg_match('#^/api/v1/depots/(\d+)$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'GET') {
        requireAuth();
        $id = (int)$m[1];
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
        if ($code === '') $code = uniqid('D');
        $exists = DB::query('SELECT id FROM depots WHERE code = :c LIMIT 1', [':c' => $code]);
        if ($exists) {
            http_response_code(409);
            echo json_encode(['error' => 'Code déjà utilisé']);
            exit;
        }
        $managerUserId = isset($data['manager_user_id']) ? (int)$data['manager_user_id'] : null;
        $managerName = $data['manager_name'] ?? null;
        if ($managerUserId) {
            $uRow = DB::query('SELECT id,name FROM users WHERE id=:id', [':id' => $managerUserId])[0] ?? null;
            if ($uRow) {
                if (!$managerName) $managerName = $uRow['name'];
            } else {
                $managerUserId = null;
            }
        }
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
        requireAuth();
        $id = (int)$m[1];
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        DB::execute('UPDATE depots SET latitude=:lat, longitude=:lng, updated_at=NOW() WHERE id=:id', [':lat' => $data['latitude'], ':lng' => $data['longitude'], ':id' => $id]);
        echo json_encode(['updated' => true]);
        exit;
    }
    // Clients listing (with balance)
    if ($path === '/api/v1/clients' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $u = requireAuth();
        requirePermission($u, 'clients', 'view');
        $rows = DB::query('SELECT c.id,c.name,c.phone,c.address,c.latitude,c.longitude,c.photo_path,c.created_at,
            (SELECT COALESCE(SUM(s.total_amount) - SUM(s.amount_paid), 0) FROM sales s WHERE s.client_id = c.id) AS balance
            FROM clients c ORDER BY c.id DESC');
        echo json_encode($rows);
        exit;
    }
    // Create client (supports JSON and multipart)
    if ($path === '/api/v1/clients' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $auth = requireAuth();
        requirePermission($auth, 'clients', 'edit');
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $data = [];
        if (stripos($contentType, 'application/json') !== false) {
            $data = json_decode(file_get_contents('php://input'), true) ?: [];
        } else {
            $data = $_POST ?: (json_decode(file_get_contents('php://input'), true) ?: []);
        }
        $photo = save_upload('photo');
        $cModel = new Client();
        $id = $cModel->insert([
            'name' => $data['name'] ?? 'Client',
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'latitude' => isset($data['latitude']) ? $data['latitude'] : null,
            'longitude' => isset($data['longitude']) ? $data['longitude'] : null,
            'photo_path' => $photo,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        echo json_encode(['id' => $id, 'photo_path' => $photo]);
        exit;
    }
    // Update client basic info / photo
    if (preg_match('#^/api/v1/clients/(\d+)$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'PATCH') {
        $auth = requireAuth();
        requirePermission($auth, 'clients', 'edit');
        $id = (int)$m[1];
        // Support JSON or multipart (photo)
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            $data = json_decode(file_get_contents('php://input'), true) ?: [];
            DB::execute('UPDATE clients SET name=:n, phone=:p, address=:a, updated_at=NOW() WHERE id=:id', [':n' => $data['name'] ?? 'Client', ':p' => $data['phone'] ?? null, ':a' => $data['address'] ?? null, ':id' => $id]);
            echo json_encode(['updated' => true]);
        } else {
            $data = $_POST;
            $photo = save_upload('photo');
            $params = [':n' => $data['name'] ?? 'Client', ':p' => $data['phone'] ?? null, ':a' => $data['address'] ?? null, ':id' => $id];
            $sql = 'UPDATE clients SET name=:n, phone=:p, address=:a';
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
    // Get single client (with balance)
    if (preg_match('#^/api/v1/clients/(\d+)$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $u = requireAuth();
        requirePermission($u, 'clients', 'view');
        $id = (int)$m[1];
        $row = DB::query('SELECT c.id,c.name,c.phone,c.address,c.latitude,c.longitude,c.photo_path,c.created_at,
                (SELECT COALESCE(SUM(s.total_amount) - SUM(s.amount_paid), 0) FROM sales s WHERE s.client_id = c.id) AS balance
            FROM clients c WHERE c.id = :id LIMIT 1', [':id' => $id])[0] ?? null;
        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            exit;
        }
        echo json_encode($row);
        exit;
    }
    // Update client geo
    if (preg_match('#^/api/v1/clients/(\d+)/geo$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'PATCH') {
        $auth = requireAuth();
        requirePermission($auth, 'clients', 'edit');
        $id = (int)$m[1];
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        DB::execute('UPDATE clients SET latitude=:lat, longitude=:lng, updated_at=NOW() WHERE id=:id', [':lat' => $data['latitude'], ':lng' => $data['longitude'], ':id' => $id]);
        echo json_encode(['updated' => true]);
        exit;
    }
    // Stock movement
    if ($path === '/api/v1/stock/movement' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $auth = requireAuth();
        $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        foreach (['depot_id', 'product_id', 'type', 'quantity'] as $f) if (!isset($data[$f])) {
            http_response_code(422);
            echo json_encode(['error' => "Missing $f"]);
            exit;
        }
        $sm = new StockMovement();
        $sm->move((int)$data['depot_id'], (int)$data['product_id'], $data['type'], (int)$data['quantity']);
        selfAdjustStock((int)$data['depot_id'], (int)$data['product_id'], $data['type'], (int)$data['quantity']);
        echo json_encode(['ok' => true]);
        exit;
    }
    // Create sale with items + optional payment (with strict stock validation)
    if ($path === '/api/v1/sales' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $auth = requireAuth();
        $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $items = $data['items'] ?? [];
        if (!$items) {
            http_response_code(422);
            echo json_encode(['error' => 'Items required']);
            exit;
        }
        // Validate stock availability for each line
        $depotId = (int)$data['depot_id'];
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
            $smModel->move((int)$data['depot_id'], (int)$it['product_id'], 'out', (int)$it['quantity'], date('Y-m-d H:i:s'), $saleId, 'sale');
            Stock::adjust($depotId, (int)$it['product_id'], 'out', (int)$it['quantity']);
        }
        if (($data['payment_amount'] ?? 0) > 0) {
            $saleModel->addPayment($saleId, (int)$data['payment_amount']);
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
    // Sales listing by livreur or client
    if ($path === '/api/v1/sales' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        requireAuth();
        $w = [];
        $p = [];
        if (!empty($_GET['client_id'])) {
            $w[] = 'client_id = :client';
            $p[':client'] = (int)$_GET['client_id'];
        }
        if (!empty($_GET['user_id'])) {
            $w[] = 'user_id = :user';
            $p[':user'] = (int)$_GET['user_id'];
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
    // Products listing (optional q search)
    if ($path === '/api/v1/products' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        requireAuth();
        $q = trim($_GET['q'] ?? '');
        if ($q !== '') {
            $like = '%' . $q . '%';
            $rows = DB::query('SELECT p.id,p.name,p.sku,p.unit_price,p.description,p.image_path,p.active, (
                SELECT COALESCE(SUM(s.quantity),0) FROM stocks s WHERE s.product_id = p.id
            ) AS stock_total FROM products p WHERE p.name LIKE :q OR p.sku LIKE :q ORDER BY p.id DESC', [':q' => $like]);
        } else {
            $rows = DB::query('SELECT p.id,p.name,p.sku,p.unit_price,p.description,p.image_path,p.active, (
                SELECT COALESCE(SUM(s.quantity),0) FROM stocks s WHERE s.product_id = p.id
            ) AS stock_total FROM products p ORDER BY p.id DESC');
        }
        echo json_encode($rows);
        exit;
    }
    // Stocks by depot for a product
    if ($path === '/api/v1/stocks' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        requireAuth();
        $pid = (int)($_GET['product_id'] ?? 0);
        if ($pid <= 0) {
            http_response_code(422);
            echo json_encode(['error' => 'product_id required']);
            exit;
        }
        $rows = DB::query('SELECT s.depot_id, d.name AS depot_name, d.code AS depot_code, s.quantity FROM stocks s JOIN depots d ON d.id = s.depot_id WHERE s.product_id = :p ORDER BY d.name ASC', [':p' => $pid]);
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
    // Users management (admin)
    if ($path === '/api/v1/users' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $u = requireAuth();
        requirePermission($u, 'users', 'view');
        $role = trim($_GET['role'] ?? '');
        $q = trim($_GET['q'] ?? '');
        $depotId = isset($_GET['depot_id']) && $_GET['depot_id'] !== '' ? (int)$_GET['depot_id'] : null;
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
        $sql = 'SELECT id,name,email,role,depot_id,permissions,created_at FROM users';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id DESC';
        $rows = DB::query($sql, $params);
        echo json_encode($rows);
        exit;
    }
    if ($path === '/api/v1/users' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $u = requireAuth();
        requirePermission($u, 'users', 'edit');
        $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $hash = password_hash($data['password'] ?? 'secret123', PASSWORD_BCRYPT);
        $permsJson = isset($data['permissions']) ? json_encode($data['permissions']) : null;
        DB::execute('INSERT INTO users(name,email,password_hash,role,depot_id,permissions,created_at) VALUES(:n,:e,:h,:r,:d,:p,NOW())', [':n' => $data['name'] ?? 'User', ':e' => $data['email'] ?? '', ':h' => $hash, ':r' => $data['role'] ?? 'gerant', ':d' => (int)($data['depot_id'] ?? 1), ':p' => $permsJson]);
        echo json_encode(['created' => true]);
        exit;
    }
    if (preg_match('#^/api/v1/users/(\d+)$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $u = requireAuth();
        requirePermission($u, 'users', 'view');
        $id = (int)$m[1];
        $row = DB::query('SELECT id,name,email,role,depot_id,permissions,created_at FROM users WHERE id=:id LIMIT 1', [':id' => $id])[0] ?? null;
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
        DB::execute('UPDATE users SET ' . implode(',', $sets) . ', updated_at=NOW() WHERE id=:id', $params);
        echo json_encode(['updated' => true]);
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
    // Summary endpoint (aggregated for dashboard)
    if ($path === '/api/v1/summary' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        requireAuth();
        $stockTotal = DB::query('SELECT COALESCE(SUM(quantity),0) qty FROM stocks')[0]['qty'] ?? 0;
        $stockLines = DB::query('SELECT s.quantity, p.name FROM stocks s JOIN products p ON p.id=s.product_id ORDER BY s.quantity DESC LIMIT 10');
        $topBalances = DB::query('SELECT c.id, c.name, (SUM(s.total_amount) - SUM(s.amount_paid)) AS balance FROM sales s JOIN clients c ON c.id = s.client_id GROUP BY c.id,c.name HAVING balance > 0 ORDER BY balance DESC LIMIT 5');
        $today = date('Y-m-d');
        $daily = ReportService::daily(1, $today); // depot 1 par défaut

        // Quick stats
        $caToday = (int)(DB::query('SELECT COALESCE(SUM(total_amount),0) v FROM sales WHERE DATE(sold_at)=CURDATE()')[0]['v'] ?? 0);
        $salesToday = (int)(DB::query('SELECT COUNT(*) c FROM sales WHERE DATE(sold_at)=CURDATE()')[0]['c'] ?? 0);
        $activeClients30 = (int)(DB::query('SELECT COUNT(DISTINCT client_id) c FROM sales WHERE sold_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)')[0]['c'] ?? 0);
        // Sparkline last 7 days revenue (ensure 7 points)
        $rows = DB::query('SELECT DATE(sold_at) d, SUM(total_amount) v FROM sales WHERE sold_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY DATE(sold_at) ORDER BY d ASC');
        $map = [];
        foreach ($rows as $r) {
            $map[$r['d']] = (int)$r['v'];
        }
        $spark = [];
        for ($i = 6; $i >= 0; $i--) {
            $dt = date('Y-m-d', strtotime("-$i day"));
            $spark[] = ['date' => $dt, 'value' => ($map[$dt] ?? 0)];
        }

        echo json_encode([
            'stock_total' => (int)$stockTotal,
            'stock_items' => $stockLines,
            'top_balances' => $topBalances,
            'daily' => $daily,
            'quick_stats' => [
                'ca_today' => $caToday,
                'sales_today' => $salesToday,
                'active_clients' => $activeClients30,
                'window' => '30d'
            ],
            'sparkline' => $spark
        ]);
        exit;
    }
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
    exit;
}

// Web pages
if ($path === '/' || $path === '/dashboard') {
    // Enforce login
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/login');
        exit;
    }
    include __DIR__ . '/../views/layout/header.php';
    include __DIR__ . '/../views/dashboard.php';
    include __DIR__ . '/../views/layout/footer.php';
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
    include __DIR__ . '/../views/layout/header.php';
    include __DIR__ . '/../views/depots_map.php';
    include __DIR__ . '/../views/layout/footer.php';
    exit;
}

// Admin pages (simple CRUD)
if ($path === '/products') {
    include __DIR__ . '/../views/layout/header.php';
    include __DIR__ . '/../views/products.php';
    include __DIR__ . '/../views/layout/footer.php';
    exit;
}
if ($path === '/products/new') {
    include __DIR__ . '/../views/layout/header.php';
    include __DIR__ . '/../views/products_new.php';
    include __DIR__ . '/../views/layout/footer.php';
    exit;
}
if ($path === '/products/edit') {
    include __DIR__ . '/../views/layout/header.php';
    include __DIR__ . '/../views/products_edit.php';
    include __DIR__ . '/../views/layout/footer.php';
    exit;
}
if ($path === '/products/view') {
    include __DIR__ . '/../views/layout/header.php';
    include __DIR__ . '/../views/product_view.php';
    include __DIR__ . '/../views/layout/footer.php';
    exit;
}
if ($path === '/clients') {
    include __DIR__ . '/../views/layout/header.php';
    include __DIR__ . '/../views/clients.php';
    include __DIR__ . '/../views/layout/footer.php';
    exit;
}
if ($path === '/clients/new') {
    include __DIR__ . '/../views/layout/header.php';
    include __DIR__ . '/../views/clients_form.php';
    include __DIR__ . '/../views/layout/footer.php';
    exit;
}
if ($path === '/clients/edit') {
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
    include __DIR__ . '/../views/layout/header.php';
    include __DIR__ . '/../views/users_form.php';
    include __DIR__ . '/../views/layout/footer.php';
    exit;
}
if ($path === '/depots') {
    include __DIR__ . '/../views/layout/header.php';
    include __DIR__ . '/../views/depots.php';
    include __DIR__ . '/../views/layout/footer.php';
    exit;
}
if ($path === '/depots/new') {
    include __DIR__ . '/../views/layout/header.php';
    include __DIR__ . '/../views/depots_new.php';
    include __DIR__ . '/../views/layout/footer.php';
    exit;
}
if ($path === '/depots/edit') {
    include __DIR__ . '/../views/layout/header.php';
    include __DIR__ . '/../views/depots_edit.php';
    include __DIR__ . '/../views/layout/footer.php';
    exit;
}
if ($path === '/orders') {
    include __DIR__ . '/../views/layout/header.php';
    include __DIR__ . '/../views/orders.php';
    include __DIR__ . '/../views/layout/footer.php';
    exit;
}
// Orders new form
if ($path === '/orders/new') {
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

// Stock transfers page
if ($path === '/transfers') {
    include __DIR__ . '/../views/layout/header.php';
    include __DIR__ . '/../views/transfers.php';
    include __DIR__ . '/../views/layout/footer.php';
    exit;
}

// Stocks by depot page
if ($path === '/stocks') {
    include __DIR__ . '/../views/layout/header.php';
    include __DIR__ . '/../views/stocks.php';
    include __DIR__ . '/../views/layout/footer.php';
    exit;
}

http_response_code(404);
echo 'Page introuvable';
