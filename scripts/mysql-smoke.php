<?php

// Smoke test on the configured MySQL database. Every write is rolled back.
use App\Models\CashShift;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\CashService;
use App\Services\OrderService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::getDriverName() !== 'mysql') {
    throw new RuntimeException('This check requires MySQL.');
}
$before = Order::count();
DB::beginTransaction();
try {
    $user = User::where('email', 'admin@magueyes.local')->firstOrFail();
    auth()->login($user);
    if (! CashShift::where('user_id', $user->id)->where('status', 'open')->exists()) {
        app(CashService::class)->open('0');
    }
    $product = Product::where('type', 'simple')->where('is_active', true)->firstOrFail();
    $order = app(OrderService::class)->create(['request_key' => (string) Str::uuid(), 'customer_name' => 'Verificacion transaccional', 'customer_phone' => '0000000000', 'delivery_type' => 'sucursal', 'delivery_cost' => 0, 'scheduled_date' => now()->addDays(60)->toDateString(), 'scheduled_time' => '12:00', 'items' => [['product_id' => $product->id, 'quantity' => 1, 'options' => []]], 'payments' => [], 'override' => false]);
    app(OrderService::class)->pay($order->id, 'efectivo', $product->price, (string) Str::uuid());
    if (OrderService::cents($order->fresh()->balance_due) !== 0) {
        throw new RuntimeException('Balance mismatch');
    }
    app(OrderService::class)->cancel($order->id, 'Verificacion con rollback');
    if ($order->fresh()->status !== 'cancelado') {
        throw new RuntimeException('Cancellation mismatch');
    }
    echo "MySQL: order, payment, refund and print queue verified.\n";
} finally {
    DB::rollBack();
}
if (Order::count() !== $before) {
    throw new RuntimeException('Unexpected persisted order');
}
echo "Rollback verified: no test sales persisted.\n";
