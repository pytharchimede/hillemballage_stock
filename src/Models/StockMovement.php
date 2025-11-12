<?php

namespace App\Models;

use App\Support\DB;

class StockMovement extends BaseModel
{
    protected string $table = 'stock_movements';

    public function move(int $depotId, int $productId, string $type, int $quantity, ?string $movedAt = null, ?int $relatedSaleId = null, ?string $note = null): int
    {
        return $this->insert([
            'depot_id' => $depotId,
            'product_id' => $productId,
            'type' => $type,
            'quantity' => $quantity,
            'related_sale_id' => $relatedSaleId,
            'note' => $note,
            'moved_at' => $movedAt ?: date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
