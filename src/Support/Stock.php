<?php

namespace App\Support;

use App\Support\DB;

class Stock
{
    public static function adjust(int $depotId, int $productId, string $type, int $qty): void
    {
        DB::execute('INSERT IGNORE INTO stocks (depot_id, product_id, quantity, created_at) VALUES (:d,:p,0,NOW())', [':d' => $depotId, ':p' => $productId]);
        $op = in_array($type, ['in', 'return']) ? '+' : '-';
        DB::execute("UPDATE stocks SET quantity = GREATEST(0, quantity $op :q), updated_at = NOW() WHERE depot_id = :d AND product_id = :p", [':q' => $qty, ':d' => $depotId, ':p' => $productId]);
    }

    public static function available(int $depotId, int $productId): int
    {
        $rows = DB::query('SELECT quantity FROM stocks WHERE depot_id = :d AND product_id = :p', [':d' => $depotId, ':p' => $productId]);
        return (int)($rows[0]['quantity'] ?? 0);
    }
}
