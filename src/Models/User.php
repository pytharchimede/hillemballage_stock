<?php

namespace App\Models;

use App\Support\DB;

class User extends BaseModel
{
    protected string $table = 'users';

    public function findByEmail(string $email): ?array
    {
        $rows = DB::query('SELECT * FROM users WHERE email = :e LIMIT 1', [':e' => $email]);
        return $rows[0] ?? null;
    }

    public function findByToken(string $token): ?array
    {
        $rows = DB::query('SELECT * FROM users WHERE api_token = :t LIMIT 1', [':t' => $token]);
        return $rows[0] ?? null;
    }

    public function createToken(int $id): string
    {
        $token = bin2hex(random_bytes(24));
        DB::execute('UPDATE users SET api_token = :t WHERE id = :id', [':t' => $token, ':id' => $id]);
        return $token;
    }
}
