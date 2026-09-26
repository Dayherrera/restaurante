<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('phone', 20)->unique();
            $t->string('street', 150)->nullable();
            $t->string('number', 30)->nullable();
            $t->string('neighborhood', 120)->nullable();
            $t->string('city', 120)->default('COMITÁN DE DOMÍNGUEZ');
            $t->string('state', 100)->default('CHIAPAS');
            $t->string('references', 500)->nullable();
            $t->timestamps();
            $t->index('name');
        });
        Schema::table('orders', fn (Blueprint $t) => $t->foreignId('customer_id')->nullable()->constrained());
        Schema::table('categories', fn (Blueprint $t) => $t->boolean('visible_in_pos')->default(true));
        Schema::create('modifier_groups', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained();
            $t->string('name', 100);
            $t->unsignedInteger('max_choices')->default(1);
            $t->boolean('required')->default(false);
            $t->timestamps();
        });
        Schema::create('modifier_group_options', function (Blueprint $t) {
            $t->id();
            $t->foreignId('modifier_group_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained();
            $t->unique(['modifier_group_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modifier_group_options');
        Schema::dropIfExists('modifier_groups');
        Schema::table('categories', fn (Blueprint $t) => $t->dropColumn('visible_in_pos'));
        Schema::table('orders', fn (Blueprint $t) => $t->dropConstrainedForeignId('customer_id'));
        Schema::dropIfExists('customers');
    }
};
