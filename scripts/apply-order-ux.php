<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
use Database\Seeders\MenuUxSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

if (DB::getDatabaseName() !== 'restaurant_db' || DB::getDriverName() !== 'mysql') {
    throw new RuntimeException('Wrong database.');
}
$tables = DB::connection()->getPdo()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$prices = DB::table('products')->pluck('price', 'id')->all();
$users = DB::table('users')->get()->toJson();
$orders = DB::table('orders')->count();
Artisan::call('down', ['--retry' => 60]);
try {
    $dir = storage_path('app/backups');
    if (! is_dir($dir) && ! mkdir($dir, 0700, true)) {
        throw new RuntimeException('Cannot create backup directory.');
    }
    $backup = $dir.'/restaurant_db-before-order-ux-'.date('Ymd-His').'.sql';
    $pdo = DB::connection()->getPdo();
    $sql = "-- Backup before order UX migration. Restore into an EMPTY database.\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n";
    DB::transaction(function () use ($tables, $pdo, &$sql) {
        foreach ($tables as $table) {
            $safe = str_replace('`', '``', $table);
            $create = $pdo->query("SHOW CREATE TABLE `$safe`")->fetch(PDO::FETCH_NUM);
            $sql .= $create[1].";\n";
            foreach (DB::table($table)->get() as $record) {
                $row = (array) $record;
                $columns = implode(',', array_map(fn ($k) => '`'.str_replace('`', '``', $k).'`', array_keys($row)));
                $values = implode(',', array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), array_values($row)));
                $sql .= "INSERT INTO `$safe` ($columns) VALUES ($values);\n";
            }
        }
    });
    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
    if (file_put_contents($backup, $sql) !== strlen($sql) || hash_file('sha256', $backup) !== hash('sha256', $sql)) {
        throw new RuntimeException('Backup verification failed; no records cleared.');
    }
    echo 'Backup verified: '.$backup.PHP_EOL;
    if (Artisan::call('migrate', ['--force' => true]) !== 0) {
        throw new RuntimeException(Artisan::output());
    }

    DB::transaction(function () use ($prices, $users, $orders) {
        (new MenuUxSeeder)->run();
        if ($prices !== DB::table('products')->whereIn('id', array_keys($prices))->pluck('price', 'id')->all()) {
            throw new RuntimeException('Existing prices changed.');
        }
        if ($users !== DB::table('users')->get()->toJson() || $orders !== DB::table('orders')->count()) {
            throw new RuntimeException('Existing access or orders changed.');
        }
    });
    Artisan::call('view:clear');
    echo json_encode(['existing_prices_preserved' => true, 'users_preserved' => true, 'orders_preserved' => $orders, 'products' => DB::table('products')->count(), 'customers' => DB::table('customers')->count(), 'groups' => DB::table('modifier_groups')->count()], JSON_PRETTY_PRINT).PHP_EOL;
} finally {
    Artisan::call('up');
}
