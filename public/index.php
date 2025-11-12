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
    if (!$token) return null;
    $uModel = new User();
    return $uModel->findByToken($token);
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
    // Auth login -> token
    if ($path === '/api/v1/auth/login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
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
        $rows = DB::query('SELECT id,name,code,latitude,longitude FROM depots');
        echo json_encode($rows);
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
    // Clients listing
    if ($path === '/api/v1/clients' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        requireAuth();
        $rows = DB::query('SELECT id,name,phone,latitude,longitude,created_at FROM clients ORDER BY id DESC');
        echo json_encode($rows);
        exit;
    }
    // Create client
    if ($path === '/api/v1/clients' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $auth = requireAuth();
        $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $cModel = new Client();
        $id = $cModel->insert([
            'name' => $data['name'] ?? 'Client',
            'phone' => $data['phone'] ?? null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        echo json_encode(['id' => $id]);
        exit;
    }
    // Update client geo
    if (preg_match('#^/api/v1/clients/(\d+)/geo$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'PATCH') {
        requireAuth();
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

http_response_code(404);
echo 'Page introuvable';
