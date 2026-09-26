<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('is_active')->default(true);
        });
        Schema::create('print_areas', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->timestamps();
        });
        Schema::create('categories', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->timestamps();
        });
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->foreignId('category_id')->constrained();
            $t->foreignId('print_area_id')->constrained();
            $t->string('name', 150);
            $t->decimal('price', 10, 2);
            $t->enum('type', ['simple', 'compuesto'])->default('simple');
            $t->unsignedInteger('max_choices')->default(0);
            $t->unsignedInteger('hourly_limit')->default(0);
            $t->unsignedInteger('daily_limit')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('product_options', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained();
            $t->string('option_name', 100);
            $t->timestamps();
        });
        Schema::create('delivery_drivers', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('phone', 25)->nullable();
            $t->boolean('is_external')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('cash_shifts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->dateTime('opened_at');
            $t->dateTime('closed_at')->nullable();
            $t->decimal('initial_amount', 10, 2);
            $t->decimal('final_cash_real', 10, 2)->nullable();
            $t->decimal('final_transfer_real', 10, 2)->nullable();
            $t->decimal('final_card_real', 10, 2)->nullable();
            $t->json('closing_summary')->nullable();
            $t->enum('status', ['open', 'closed'])->default('open');
            $t->timestamps();
            $t->index(['user_id', 'status']);
        });
        Schema::create('cash_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cash_shift_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->enum('type', ['entrada', 'retiro']);
            $t->decimal('amount', 10, 2);
            $t->string('reason');
            $t->timestamps();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->uuid('request_key')->unique();
            $t->string('order_number', 25)->unique();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('cash_shift_id')->constrained();
            $t->foreignId('delivery_driver_id')->nullable()->constrained();
            $t->string('customer_name', 120);
            $t->string('customer_phone', 25);
            $t->enum('delivery_type', ['sucursal', 'domicilio']);
            $t->string('delivery_address', 500)->nullable();
            $t->decimal('delivery_cost', 10, 2)->default(0);
            $t->date('scheduled_date');
            $t->time('scheduled_time');
            $t->decimal('total', 10, 2);
            $t->decimal('amount_paid', 10, 2)->default(0);
            $t->decimal('balance_due', 10, 2);
            $t->enum('status', ['pendiente', 'en_preparacion', 'listo', 'en_ruta', 'entregado', 'cancelado'])->default('pendiente');
            $t->text('cancellation_reason')->nullable();
            $t->timestamps();
            $t->index(['scheduled_date', 'scheduled_time', 'status']);
        });
        Schema::create('order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained();
            $t->foreignId('product_id')->constrained();
            $t->string('product_name');
            $t->foreignId('print_area_id')->constrained();
            $t->unsignedInteger('quantity');
            $t->decimal('unit_price', 10, 2);
            $t->text('kitchen_notes')->nullable();
            $t->json('selected_options')->nullable();
            $t->boolean('is_cancelled')->default(false);
            $t->timestamps();
        });
        Schema::create('order_payments', function (Blueprint $t) {
            $t->id();
            $t->uuid('request_key')->unique();
            $t->foreignId('order_id')->constrained();
            $t->foreignId('cash_shift_id')->constrained();
            $t->enum('payment_method', ['efectivo', 'transferencia', 'tarjeta']);
            $t->decimal('amount', 10, 2);
            $t->boolean('is_advance')->default(false);
            $t->timestamps();
        });
        Schema::create('order_refunds', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained();
            $t->foreignId('cash_shift_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->enum('payment_method', ['efectivo', 'transferencia', 'tarjeta']);
            $t->decimal('amount', 10, 2);
            $t->string('reason');
            $t->timestamps();
        });
        Schema::create('print_jobs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained();
            $t->foreignId('print_area_id')->constrained();
            $t->string('kind');
            $t->text('payload');
            $t->enum('status', ['pending', 'processing', 'printed', 'failed'])->default('pending');
            $t->uuid('lease_token')->nullable();
            $t->dateTime('leased_at')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->text('error')->nullable();
            $t->dateTime('printed_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'leased_at']);
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->foreignId('order_id')->nullable()->constrained();
            $t->string('action');
            $t->json('details');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'print_jobs', 'order_refunds', 'order_payments', 'order_items', 'orders', 'cash_movements', 'cash_shifts', 'delivery_drivers', 'product_options', 'products', 'categories', 'print_areas'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('is_active'));
    }
};
