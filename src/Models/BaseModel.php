<?php

namespace App\Models;

use App\Support\DB;

abstract class BaseModel
{
    protected string $table;

    public function all(): array
    {
        return DB::query("SELECT * FROM {$this->table}");
    }
    public function find(int $id): ?array
    {
        $rows = DB::query("SELECT * FROM {$this->table} WHERE id = :id", [':id' => $id]);
        return $rows[0] ?? null;
    }
    public function insert(array $data): int
    {
        $keys = array_keys($data);
        $cols = '`' . implode('`,`', $keys) . '`';
        $params = ':' . implode(',:', $keys);
        $sql = "INSERT INTO {$this->table} ($cols) VALUES ($params)";
        DB::execute($sql, array_combine(array_map(fn($k) => ":$k", $keys), array_values($data)));
        return (int)DB::conn()->lastInsertId();
    }
    public function update(int $id, array $data): bool
    {
        $sets = [];
        $params = [];
        foreach ($data as $k => $v) {
            $sets[] = "`$k` = :$k";
            $params[":$k"] = $v;
        }
        $params[':id'] = $id;
        return DB::execute("UPDATE {$this->table} SET " . implode(',', $sets) . " WHERE id = :id", $params);
    }
}
