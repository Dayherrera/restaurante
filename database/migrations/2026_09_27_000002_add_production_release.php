<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
return new class extends Migration {
 public function up(): void {
  Schema::table('orders',function(Blueprint $t){$t->timestamp('production_released_at')->nullable();$t->foreignId('production_released_by')->nullable()->constrained('users');$t->text('production_release_reason')->nullable();});
  DB::table('orders')->whereIn('status',['en_preparacion','listo','en_ruta','entregado'])->update(['production_released_at'=>now(),'production_release_reason'=>'Operación en curso al activar liberación manual']);
  DB::table('print_jobs')->where('status','pending')->whereIn('order_id',DB::table('orders')->whereNull('production_released_at')->select('id'))->whereIn('print_area_id',DB::table('print_areas')->where('name','!=','Caja')->select('id'))->update(['status'=>'failed','error'=>'Retenido: el pedido requiere liberación manual a producción.']);
 }
 public function down(): void {Schema::table('orders',function(Blueprint $t){$t->dropConstrainedForeignId('production_released_by');$t->dropColumn(['production_released_at','production_release_reason']);});}
};
