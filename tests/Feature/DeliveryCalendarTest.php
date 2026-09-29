<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\{User,Product,PrintJob,PrintArea};
use App\Services\{OrderService,CashService};
use App\Livewire\DeliveryCalendar;
use Illuminate\Support\Str;
use Livewire\Livewire;
class DeliveryCalendarTest extends TestCase {
 use RefreshDatabase;
 protected function setUp():void {parent::setUp();putenv('POS_ADMIN_PASSWORD=Testing-Password-123');$this->seed();$this->actingAs(User::first());app(CashService::class)->open('0');}
 private function order(int $days=0){return app(OrderService::class)->create(['request_key'=>(string)Str::uuid(),'customer_name'=>'Cliente calendario '.$days,'customer_phone'=>'9631234567','delivery_type'=>'sucursal','delivery_cost'=>0,'scheduled_date'=>now()->addDays($days)->toDateString(),'scheduled_time'=>'14:00','items'=>[['product_id'=>Product::where('type','simple')->whereHas('category',fn($q)=>$q->where('visible_in_pos',true))->first()->id,'quantity'=>1,'notes'=>'Sin cebolla']],'payments'=>[]]);}
 public function test_calendar_range_excludes_end_and_hides_cancelled_until_requested(){
  $a=$this->order();$b=$this->order(1);$c=$this->order(2);$b->update(['status'=>'cancelado']);
  $component=Livewire::test(DeliveryCalendar::class);
  $events=$component->instance()->events(now()->toDateString(),now()->addDays(2)->toDateString());
  $this->assertCount(1,$events);$this->assertSame((string)$a->id,$events[0]['id']);$this->assertArrayNotHasKey('total',$events[0]['extendedProps']);
  $component->set('showCancelled',true)->assertDispatched('calendar-refresh');
  $this->assertCount(2,$component->instance()->events(now()->toDateString(),now()->addDays(2)->toDateString()));
  $component->call('events','bad-date',now()->toDateString())->assertHasErrors('start');
  $this->get('/calendario')->assertOk()->assertSee('Calendario de entregas');
 }
 public function test_panels_are_read_only_and_reprint_respects_release(){
  $o=$this->order(2);$before=PrintJob::count();
  $component=Livewire::test(DeliveryCalendar::class)->call('openDay',$o->scheduled_date->toDateString())->assertSee($o->customer_name)->call('openOrder',$o->id)->assertSee('Sin cebolla')->assertSee('Programado');
  $this->assertEquals($before,PrintJob::count());$this->assertNull($o->fresh()->production_released_at);
  $component->call('reprint')->assertHasNoErrors();
  $this->assertEquals(PrintArea::where('name','Caja')->value('id'),PrintJob::latest('id')->first()->print_area_id);
  $this->assertNull($o->fresh()->production_released_at);
  $component->call('closePanel')->assertSet('orderId',null)->assertSet('day',null);
 }
 public function test_calendar_and_actions_require_permissions(){
  $o=$this->order();$u=User::create(['name'=>'Consulta','email'=>'calendar@example.test','password'=>'Test-password-123']);
  $this->actingAs($u)->get('/calendario')->assertForbidden();
  $u->givePermissionTo('orders.view');
  Livewire::test(DeliveryCalendar::class)->call('openOrder',$o->id)->call('reprint')->assertForbidden();
  Livewire::test(DeliveryCalendar::class)->call('openOrder',$o->id)->call('assignDriver')->assertForbidden();
 }
}
