<?php

use App\Models\PrintArea;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$path = __DIR__.'/../agent/config.json';
if (file_exists($path)) {
    throw new RuntimeException('Ya existe una configuracion: revisar antes de reemplazar.');
}
$printers = [];
foreach (PrintArea::all() as $area) {
    $printers[$area->id] = ['type' => 'device', 'path' => '\\\\localhost\\EC-PM-5890X', 'label' => $area->name];
    echo $area->name." -> EC-PM-5890X (USB001)\n";
}
file_put_contents($path, json_encode(['server' => rtrim(config('app.url'), '/').'/', 'token' => config('pos.print_token'), 'printers' => $printers], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
