<?php
$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbName = getenv('DB_NAME') ?: 'fidestci_hill_stock_db';
$dbUser = getenv('DB_USER') ?: 'fidestci_ulrich';
$dbPass = getenv('DB_PASS') ?: '@Succes2019';

return [
    'driver' => 'mysql',
    'host' => $dbHost,
    'port' => $dbPort,
    'database' => $dbName,
    'username' => $dbUser,
    'password' => $dbPass,
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
];
