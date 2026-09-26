<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->text('description')->nullable());
        Schema::table('order_items', fn (Blueprint $t) => $t->text('product_description')->nullable());
    }

    public function down(): void
    {
        Schema::table('order_items', fn (Blueprint $t) => $t->dropColumn('product_description'));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('description'));
    }
};
