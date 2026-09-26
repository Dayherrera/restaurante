<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_sequences', function (Blueprint $t) {
            $t->string('name')->primary();
            $t->unsignedBigInteger('value')->default(0);
        });
        $max = 0;
        foreach (DB::table('orders')->select('order_number')->cursor() as $order) {
            if (ctype_digit($order->order_number)) {
                $max = max($max, (int) $order->order_number);
            }
        }
        DB::table('order_sequences')->insert(['name' => 'orders', 'value' => $max]);
    }

    public function down(): void
    {
        Schema::dropIfExists('order_sequences');
    }
};
