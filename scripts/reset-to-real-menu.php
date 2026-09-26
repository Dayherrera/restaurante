<?php

// Explicit one-time operation: preserve access accounts and replace all business data.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
use App\Models\Product;
use Database\Seeders\RealMenuSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

if (($argv[1] ?? '') !== '--confirm=restaurant_db' || DB::getDatabaseName() !== 'restaurant_db' || DB::getDriverName() !== 'mysql') {
    throw new RuntimeException('Requires --confirm=restaurant_db and that exact MySQL database.');
}
$keep = ['users', 'roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions', 'migrations', 'print_areas'];
$clear = ['audit_logs', 'print_jobs', 'order_refunds', 'order_payments', 'order_items', 'orders', 'cash_movements', 'cash_shifts', 'delivery_drivers', 'product_options', 'products', 'categories', 'sessions', 'password_reset_tokens', 'failed_jobs', 'job_batches', 'jobs', 'cache_locks', 'cache'];
$tables = DB::connection()->getPdo()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
if (array_diff($tables, array_merge($keep, $clear))) {
    throw new RuntimeException('Unknown tables detected; inspect before clearing.');
}
if (count(json_decode(file_get_contents(database_path('data/menu-real.json')), true, 512, JSON_THROW_ON_ERROR)) !== 44) {
    throw new RuntimeException('Menu validation failed.');
}
$access = ['users', 'roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions', 'print_areas'];
$fingerprint = function () use ($access) {
    $result = [];
    foreach ($access as $t) {
        $rows = DB::table($t)->get()->map(fn ($r) => json_encode($r))->all();
        sort($rows);
        $result[$t] = hash('sha256', implode("\n", $rows));
    }

return $result;
};
$before = $fingerprint();
Artisan::call('down', ['--retry' => 60]);
try {
    $dir = storage_path('app/backups');
    if (! is_dir($dir) && ! mkdir($dir, 0700, true)) {
        throw new RuntimeException('Cannot create backup directory.');
    }
    $backup = $dir.'/restaurant_db-before-real-menu-'.date('Ymd-His').'.sql';
    $pdo = DB::connection()->getPdo();
    $sql = "-- Backup before authorized menu reset. Restore into an EMPTY database.\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n";
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
    DB::transaction(function () use ($clear, $fingerprint, $before) {
        foreach ($clear as $t) {
            DB::table($t)->delete();
        }
        (new RealMenuSeeder)->run();
        if ($before !== $fingerprint()) {
            throw new RuntimeException('Access or printing configuration changed; rolling back.');
        }
        if (Product::count() !== 44) {
            throw new RuntimeException('Menu count mismatch; rolling back.');
        }
        foreach (array_diff($clear, ['products', 'product_options', 'categories']) as $t) {
            if (DB::table($t)->count() !== 0) {
                throw new RuntimeException('Unexpected remaining rows in '.$t);
            }
        }
    }, 3);
    Artisan::call('view:clear');
    echo json_encode(['users_preserved' => DB::table('users')->count(), 'access_and_print_areas_unchanged' => true, 'products' => 44, 'categories' => DB::table('categories')->count(), 'options' => DB::table('product_options')->count(), 'orders' => DB::table('orders')->count(), 'cash_shifts' => DB::table('cash_shifts')->count(), 'print_jobs' => DB::table('print_jobs')->count()], JSON_PRETTY_PRINT).PHP_EOL;
} finally {
    Artisan::call('up');
}
