<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\{Order,Product,User,PrintJob,PrintArea};
use App\Services\{OrderService,CashService,TicketService};
class ProductionReleaseTest extends TestCase {
 use RefreshDatabase;
 protected function setUp():void {parent::setUp();putenv('POS_ADMIN_PASSWORD=Testing-Password-123');$this->seed();$this->actingAs(User::first());app(CashService::class)->open('0');}
 private function order(int $days=0):Order {return app(OrderService::class)->create(['request_key'=>(string)Str::uuid(),'customer_name'=>'Entrega '.$days,'customer_phone'=>'9631234567','delivery_type'=>'sucursal','delivery_cost'=>0,'scheduled_date'=>now()->addDays($days)->toDateString(),'scheduled_time'=>'14:00','items'=>[['product_id'=>Product::where('type','simple')->whereHas('category',fn($q)=>$q->where('visible_in_pos',true))->first()->id,'quantity'=>1]],'payments'=>[]]);}
 public function test_today_agenda_and_overdue_are_separate_and_rollover_needs_no_scheduler(){
  $today=$this->order();$future=$this->order(4);
  $this->get('/despacho')->assertOk()->assertSee('Entrega 0')->assertDontSee('Entrega 4');
  $this->get('/pedidos?agenda=1')->assertOk()->assertSee('Entrega 4')->assertDontSee('Entrega 0');
  $this->travel(4)->days();
  $this->get('/despacho')->assertOk()->assertSee('Entrega 4')->assertDontSee('Entrega 0')->assertSee('Hay 1 pedidos atrasados');
  $this->get('/despacho?view=overdue')->assertOk()->assertSee('Entrega 0')->assertDontSee('Entrega 4');
 }
 public function test_release_prints_once_and_status_is_blocked_before_release(){
  $order=$this->order();$cash=PrintArea::where('name','Caja')->value('id');
  $this->assertSame([$cash],PrintJob::pluck('print_area_id')->all());
  $this->post('/pedidos/'.$order->id.'/estado',['status'=>'en_preparacion'])->assertSessionHasErrors();
  $this->post('/pedidos/'.$order->id.'/liberar')->assertSessionHasNoErrors();
  $count=PrintJob::count();$this->assertEquals(2,$count);
  $this->post('/pedidos/'.$order->id.'/liberar')->assertSessionHasNoErrors();$this->assertEquals($count,PrintJob::count());
  $this->assertEquals(1,DB::table('audit_logs')->where('action','production_released')->count());
  $this->post('/pedidos/'.$order->id.'/estado',['status'=>'en_preparacion'])->assertSessionHasNoErrors();
 }
 public function test_future_release_requires_admin_and_reason_and_reprints_cannot_bypass(){
  $order=$this->order(4);$admin=auth()->user();
  $cook=User::create(['name'=>'Cocina','email'=>'cook@example.test','password'=>'Test-password-123']);$cook->assignRole('Cocina');
  $this->actingAs($cook)->post('/pedidos/'.$order->id.'/liberar',['reason'=>'Preparación previa'])->assertForbidden();
  $this->post('/pedidos/'.$order->id.'/estado',['status'=>'en_preparacion'])->assertSessionHasErrors();
  $this->actingAs($admin)->post('/pedidos/'.$order->id.'/liberar')->assertSessionHasErrors('reason');
  app(TicketService::class)->queue($order,'reimpresion');
  $this->assertEquals(1,PrintJob::distinct()->count('print_area_id'));
  $this->post('/pedidos/'.$order->id.'/liberar',['reason'=>'Marinado autorizado por administrador'])->assertSessionHasNoErrors();
  $this->assertEquals($admin->id,$order->fresh()->production_released_by);
  $this->get('/despacho')->assertDontSee('Entrega 4');
  $this->get('/despacho?view=early')->assertOk()->assertSee('Entrega 4');
 }
 public function test_retry_and_agent_cannot_send_unreleased_kitchen_job(){
  $order=$this->order(1);$job=PrintJob::create(['order_id'=>$order->id,'print_area_id'=>PrintArea::where('name','Cocina')->value('id'),'kind'=>'comanda','payload'=>'ANTIGUA','status'=>'failed']);
  $this->post('/impresion/'.$job->id.'/reintentar')->assertSessionHasErrors('print');
  $job->update(['status'=>'pending']);config(['pos.print_token'=>'test-token']);
  $this->withToken('test-token')->postJson('/print-agent/claim',['areas'=>[$job->print_area_id]])->assertOk()->assertJson(['job'=>null]);
 }
}
