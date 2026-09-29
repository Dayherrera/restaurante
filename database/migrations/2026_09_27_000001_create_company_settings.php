<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
return new class extends Migration {
 public function up(): void {
  Schema::create('company_settings',function(Blueprint $t){
   $t->unsignedTinyInteger('id')->primary();$t->string('business_name',150);$t->string('legal_name',200)->nullable();$t->string('rfc',13)->nullable();$t->string('address',500)->nullable();$t->string('phone',50)->nullable();$t->string('email',150)->nullable();$t->string('website',200)->nullable();$t->string('ticket_footer',500)->nullable();$t->timestamps();
  });
  DB::table('company_settings')->insert(['id'=>1,'business_name'=>'CHAROLAS LOS MAGUEYES','ticket_footer'=>'Gracias por su preferencia','created_at'=>now(),'updated_at'=>now()]);
 }
 public function down(): void {Schema::dropIfExists('company_settings');}
};
