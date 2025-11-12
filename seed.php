<?php
require __DIR__ . '/vendor/autoload.php';

use App\Support\DB;

$pdo = DB::conn();

// Insert default depot
$pdo->exec("INSERT IGNORE INTO depots (id, name, code) VALUES (1, 'Dépôt Principal', 'MAIN')");

// Products
$products = [
    ['ALU SANITEX 50M', 'ALU50', 2500],
    ['ALU SANITEX 200M', 'ALU200', 10000],
    ['ALU FOIL 60 M', 'ALU60', 2500],
    ['ESSUIE TOUT GEANT X1', 'ETX1', 2000],
    ['SAC POUBEL 100L 1X10', 'SP100', 1000],
];
$stmt = $pdo->prepare('INSERT IGNORE INTO products (name, sku, unit_price) VALUES (?,?,?)');
foreach ($products as $p) {
    $stmt->execute($p);
}

// Admin user
$password = password_hash('admin123', PASSWORD_BCRYPT);
$pdo->prepare('INSERT IGNORE INTO users (id,name,email,password_hash,role,depot_id) VALUES (1,?,?,?,?,1)')
    ->execute(['Admin', 'admin@hillemballage.local', $password, 'admin']);
// Sample stock movements for today to test reports
$today = date('Y-m-d');
$prodRows = $pdo->query('SELECT id, sku FROM products')->fetchAll();
$skuMap = [];
foreach ($prodRows as $r) {
    $skuMap[$r['sku']] = (int)$r['id'];
}
$insertMove = $pdo->prepare('INSERT INTO stock_movements (depot_id, product_id, type, quantity, moved_at) VALUES (1, ?, ?, ?, ?)');
if (isset($skuMap['ALU200'])) {
    $insertMove->execute([$skuMap['ALU200'], 'out', 1, $today . ' 10:00:00']);
}
if (isset($skuMap['ALU60'])) {
    $insertMove->execute([$skuMap['ALU60'], 'out', 72, $today . ' 09:00:00']);
    $insertMove->execute([$skuMap['ALU60'], 'return', 4, $today . ' 18:00:00']);
}

echo "Seed done.\n";
