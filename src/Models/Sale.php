<?php

namespace App\Models;

use App\Support\DB;

class Sale extends BaseModel
{
    protected string $table = 'sales';

    public function addPayment(int $saleId, int $amount): bool
    {
        DB::execute('INSERT INTO payments (sale_id, amount, paid_at, created_at) VALUES (:s,:a, NOW(), NOW())', [':s' => $saleId, ':a' => $amount]);
        DB::execute('UPDATE sales SET amount_paid = amount_paid + :a WHERE id = :s', [':a' => $amount, ':s' => $saleId]);
        // update status
        DB::execute('UPDATE sales SET status = CASE WHEN amount_paid >= total_amount THEN "paid" WHEN amount_paid > 0 THEN "partial" ELSE status END WHERE id = :s', [':s' => $saleId]);
        return true;
    }
}
