<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$pdo = DB::connection()->getPdo();
$sql = "-- Charolas Los Magueyes: reference schema only, no business data.\n-- Source of truth: database/migrations.\nSET NAMES utf8mb4;\n\n";
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    $safe = str_replace('`', '``', $table);
    $row = $pdo->query("SHOW CREATE TABLE `$safe`")->fetch(PDO::FETCH_NUM);
    $sql .= $row[1].";\n\n";
}
file_put_contents(__DIR__.'/../database/schema/mysql-schema.sql', $sql);
echo "Schema exported without data.\n";
