<?php
require __DIR__ . '/vendor/autoload.php';

use App\Support\DB;
use App\Support\SchemaSync;

$schema = require __DIR__ . '/database/schema.php';

// Ensure database exists
$dbCfg = require __DIR__ . '/config/database.php';
$dsnNoDb = sprintf('%s:host=%s;port=%s;charset=%s', $dbCfg['driver'], $dbCfg['host'], $dbCfg['port'], $dbCfg['charset']);
try {
    $pdoBootstrap = new PDO($dsnNoDb, $dbCfg['username'], $dbCfg['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdoBootstrap->exec('CREATE DATABASE IF NOT EXISTS `' . $dbCfg['database'] . '` CHARACTER SET ' . $dbCfg['charset'] . ' COLLATE ' . $dbCfg['collation']);
} catch (Throwable $e) {
    fwrite(STDERR, "Cannot create database: " . $e->getMessage() . "\n");
}

$pdo = DB::conn();
$sync = new SchemaSync($pdo);
$results = $sync->sync($schema);

echo "Migration summary\n";
foreach ($results as $r) echo " - $r\n";

echo "Done.\n";
