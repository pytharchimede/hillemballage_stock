<?php

namespace App\Services;

use App\Support\DB;

class ReportService
{
    public static function daily(int $depotId, string $date): array
    {
        // Agrège sorties et retours par produit
        $sql = "
        SELECT p.id, p.name, p.unit_price,
               COALESCE(SUM(CASE WHEN sm.type = 'out' THEN sm.quantity END),0) AS sorties,
               COALESCE(SUM(CASE WHEN sm.type = 'return' THEN sm.quantity END),0) AS retourne
        FROM products p
        LEFT JOIN stock_movements sm ON sm.product_id = p.id AND sm.depot_id = :depot AND DATE(sm.moved_at) = :d
        GROUP BY p.id, p.name, p.unit_price
        ORDER BY p.name";
        $rows = DB::query($sql, [':depot' => $depotId, ':d' => $date]);
        foreach ($rows as &$r) {
            $r['sorties'] = (int)$r['sorties'];
            $r['retourne'] = (int)$r['retourne'];
            $r['vendu'] = max(0, $r['sorties'] - $r['retourne']);
            $r['montant'] = $r['vendu'] * (int)$r['unit_price'];
        }
        $total = array_reduce($rows, fn($c, $x) => $c + (int)$x['montant'], 0);
        return ['date' => $date, 'depot_id' => $depotId, 'rows' => $rows, 'total_montant' => $total];
    }

    public static function monthly(int $depotId, string $yearMonth): array
    {
        [$y, $m] = explode('-', $yearMonth);
        $sql = "
        SELECT p.id, p.name, p.unit_price,
               COALESCE(SUM(CASE WHEN sm.type = 'out' THEN sm.quantity END),0) as sorties,
               COALESCE(SUM(CASE WHEN sm.type = 'return' THEN sm.quantity END),0) as retourne
        FROM products p
        LEFT JOIN stock_movements sm ON sm.product_id = p.id AND sm.depot_id = :depot AND YEAR(sm.moved_at) = :y AND MONTH(sm.moved_at) = :m
        GROUP BY p.id, p.name, p.unit_price
        ORDER BY p.name";
        $rows = DB::query($sql, [':depot' => $depotId, ':y' => (int)$y, ':m' => (int)$m]);
        $sumCa = 0;
        $sumUnits = 0;
        foreach ($rows as &$r) {
            $sold = max(0, (int)$r['sorties'] - (int)$r['retourne']);
            $r['units'] = $sold;
            $r['ca'] = $sold * (int)$r['unit_price'];
            $sumCa += $r['ca'];
            $sumUnits += $sold;
        }
        return ['month' => $yearMonth, 'depot_id' => $depotId, 'rows' => $rows, 'totals' => ['ca' => $sumCa, 'units' => $sumUnits]];
    }
}
