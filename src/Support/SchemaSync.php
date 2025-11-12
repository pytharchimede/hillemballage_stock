<?php

namespace App\Support;

use PDO;

class SchemaSync
{
    private PDO $pdo;
    private string $dbName;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->dbName = $this->pdo->query('SELECT DATABASE()')->fetchColumn();
    }

    public function sync(array $schema): array
    {
        $results = [];
        foreach ($schema as $table => $def) {
            if (!$this->tableExists($table)) {
                $this->createTable($table, $def);
                $results[] = "Created table $table";
            } else {
                $addedCols = $this->addMissingColumns($table, $def['columns'] ?? []);
                if ($addedCols) {
                    $results[] = "Altered $table: +" . implode(',', $addedCols);
                }
                $this->ensureIndexes($table, $def['indexes'] ?? []);
            }
        }
        return $results ?: ['Schema up-to-date'];
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
        $stmt->execute([$this->dbName, $table]);
        return (bool)$stmt->fetchColumn();
    }

    private function createTable(string $table, array $def): void
    {
        $colsSql = [];
        foreach ($def['columns'] as $name => $cdef) {
            $colsSql[] = $this->columnSql($name, $cdef);
        }
        foreach ($def['indexes'] ?? [] as $idx) {
            $colsSql[] = $idx;
        }
        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (%s) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;',
            $table,
            implode(',', $colsSql)
        );
        $this->pdo->exec($sql);
    }

    private function addMissingColumns(string $table, array $columns): array
    {
        $existing = $this->getColumns($table);
        $added = [];
        foreach ($columns as $name => $cdef) {
            if (!isset($existing[$name])) {
                $sql = sprintf('ALTER TABLE `%s` ADD COLUMN %s;', $table, $this->columnSql($name, $cdef));
                $this->pdo->exec($sql);
                $added[] = $name;
            }
        }
        return $added;
    }

    private function ensureIndexes(string $table, array $indexes): void
    {
        if (!$indexes) return;
        // naive: try adding, ignore duplicates errors
        foreach ($indexes as $idx) {
            try {
                $this->pdo->exec("ALTER TABLE `$table` ADD $idx");
            } catch (\Throwable $e) { /* ignore */
            }
        }
    }

    private function getColumns(string $table): array
    {
        $stmt = $this->pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
        $stmt->execute([$this->dbName, $table]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['COLUMN_NAME']] = true;
        }
        return $out;
    }

    private function columnSql(string $name, array $c): string
    {
        $type = strtolower($c['type'] ?? 'varchar');
        $length = $c['length'] ?? null;
        $nullable = $c['nullable'] ?? false;
        $unsigned = $c['unsigned'] ?? false;
        $extra = $c['extra'] ?? '';
        $default = $c['default'] ?? null;

        $sqlType = match ($type) {
            'int' => 'INT' . ($unsigned ? ' UNSIGNED' : '') . ($length ? "($length)" : ''),
            'bigint' => 'BIGINT' . ($unsigned ? ' UNSIGNED' : ''),
            'tinyint' => 'TINYINT' . ($unsigned ? ' UNSIGNED' : ''),
            'decimal' => 'DECIMAL' . ($length ? "($length)" : '(10,2)'),
            'datetime' => 'DATETIME',
            'date' => 'DATE',
            'text' => 'TEXT',
            default => 'VARCHAR' . ($length ? "($length)" : '(191)'),
        };

        $pieces = ["`$name`", $sqlType];
        $pieces[] = $nullable ? 'NULL' : 'NOT NULL';
        if ($default !== null) {
            if (is_numeric($default)) $pieces[] = 'DEFAULT ' . $default;
            elseif (is_string($default) && strtoupper($default) === 'CURRENT_TIMESTAMP') $pieces[] = 'DEFAULT CURRENT_TIMESTAMP';
            else $pieces[] = 'DEFAULT ' . $this->pdo->quote((string)$default);
        }
        if ($extra) $pieces[] = $extra;
        return implode(' ', $pieces);
    }
}
