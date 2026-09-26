<?php
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
echo json_encode(['products'=>DB::table('products')->count(),'visible_products'=>DB::table('products')->join('categories','categories.id','=','products.category_id')->where('visible_in_pos',true)->count(),'customers'=>DB::table('customers')->count(),'orders'=>DB::table('orders')->count(),'modifier_groups'=>DB::table('modifier_groups')->count(),'pending_migrations'=>array_values(array_diff(array_map(fn($f)=>basename($f,'.php'),glob(database_path('migrations/*.php'))),DB::table('migrations')->pluck('migration')->all()))],JSON_PRETTY_PRINT).PHP_EOL;
