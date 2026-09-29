<?php

namespace Tests\Feature;

use App\Models\PrintArea;
use App\Models\PrintJob;
use App\Models\Product;
use App\Models\User;
use App\Services\CashService;
use App\Services\OrderService;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TicketFolioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('POS_ADMIN_PASSWORD=Testing-Password-123');
        $this->seed();
        $this->actingAs(User::where('email', 'admin@magueyes.local')->first());
        app(CashService::class)->open('0');
    }

    private function data(): array
    {
        $p = Product::where('name', 'Rib Eye (prueba)')->first();
        $g = $p->modifierGroups()->where('name', 'Término')->first();

        return ['request_key' => (string) Str::uuid(), 'customer_name' => 'Cliente de prueba', 'customer_phone' => '9631234567', 'delivery_type' => 'sucursal', 'delivery_cost' => 0, 'scheduled_date' => now()->addDay()->toDateString(), 'scheduled_time' => '14:30', 'items' => [['product_id' => $p->id, 'quantity' => 1, 'notes' => 'Sin sal; preparar separado', 'modifiers' => [$g->id => [$g->options->first()->id => 1]]]], 'payments' => [['method' => 'efectivo', 'amount' => 100]]];
    }

    public function test_folios_are_sequential_and_retries_and_rollbacks_do_not_consume_them()
    {
        $s = app(OrderService::class);
        $data = $this->data();
        $a = $s->create($data);
        $this->assertSame('0000001', $a->order_number);
        $this->assertSame($a->id, $s->create($data)->id);
        DB::beginTransaction();
        try {
            $this->assertSame('0000002', $s->create($this->data())->order_number);
        } finally {
            DB::rollBack();
        }
        $this->assertSame('0000002', $s->create($this->data())->order_number);
    }

    public function test_receipt_layout_and_kitchen_dispatch_only_show_choices_and_notes()
    {
        $p = Product::where('name', 'Rib Eye (prueba)')->first();
        $p->update(['description' => 'DESCRIPCION QUE NO DEBE IMPRIMIRSE']);
        $o = app(OrderService::class)->create($this->data());
        $jobs = PrintJob::where('order_id', $o->id)->get();
        $this->assertCount(1, $jobs);
        foreach ($jobs as $job) {
            $this->assertStringNotContainsString('DESCRIPCION QUE NO DEBE IMPRIMIRSE', $job->payload);
            $this->assertStringContainsString('Termino medio', $job->payload);
            $this->assertStringContainsString('Sin sal; preparar separado', $job->payload);
            $this->assertStringContainsString('FOLIO: 0000001', $job->payload);
            foreach (explode("\n", $job->payload) as $line) {
                $this->assertLessThanOrEqual(32, strlen($line));
            }
        }
        $cash = $jobs->firstWhere('print_area_id', PrintArea::where('name', 'Caja')->value('id'))->payload;
        $this->assertStringContainsString('1 x $295.00', $cash);
        $this->assertStringContainsString('SALDO PENDIENTE', $cash);
        $this->assertStringContainsString('$195.00', $cash);
        app(OrderService::class)->release($o->id, 'Prueba anticipada');
        $this->get('/despacho?view=early')->assertOk()->assertSee('Término medio')->assertSee('Sin sal; preparar separado')->assertDontSee('DESCRIPCION QUE NO DEBE IMPRIMIRSE');
        app(TicketService::class)->queue($o, 'cancelacion', [$o->items->first()->id]);
        $this->assertStringContainsString('MOVIMIENTO PARCIAL', PrintJob::where('print_area_id', PrintArea::where('name', 'Caja')->value('id'))->latest('id')->first()->payload);
    }
    public function test_botanas_heading_is_hidden_but_choices_remain_and_old_numeric_folios_are_padded(): void
    {
        $order = app(OrderService::class)->create($this->data());
        DB::table('orders')->where('id', $order->id)->update(['order_number' => '1']);
        $order->refresh();
        $this->assertSame('0000001', $order->order_number);
        $order->items->first()->update(['selected_options' => [['group'=>'Botanas a elegir','name'=>'Carne asada','quantity'=>12]]]);
        PrintJob::query()->delete();
        app(TicketService::class)->queue($order, 'reimpresion');
        foreach (PrintJob::all() as $job) {
            $this->assertStringNotContainsString('Botanas a elegir', $job->payload);
            $this->assertStringContainsString('12 x Carne asada', $job->payload);
            $this->assertStringContainsString('FOLIO: 0000001', $job->payload);
        }
        app(OrderService::class)->release($order->id, 'Prueba anticipada');
        $this->get('/despacho?view=early')->assertOk()->assertSee('0000001')->assertSee('Carne asada')->assertDontSee('Botanas a elegir');
    }
    public function test_payment_only_queues_cash_receipt_and_clears_balance_highlight(): void
    {
        $order = app(OrderService::class)->create($this->data());
        $this->get('/pedidos?'.http_build_query(['date_from'=>now()->addDay()->toDateString(),'date_to'=>now()->addDay()->toDateString()]))->assertOk()->assertSee('Saldo pendiente')->assertSee('order-unpaid');
        $before = PrintJob::max('id');
        $key = (string) Str::uuid();
        app(OrderService::class)->pay($order->id, 'efectivo', 195, $key);
        app(OrderService::class)->pay($order->id, 'efectivo', 195, $key);
        $jobs = PrintJob::where('id', '>', $before)->get();
        $this->assertCount(1, $jobs);
        $this->assertEquals(PrintArea::where('name', 'Caja')->value('id'), $jobs->first()->print_area_id);
        $this->assertSame('pago', $jobs->first()->kind);
        $this->get('/pedidos?'.http_build_query(['date_from'=>now()->addDay()->toDateString(),'date_to'=>now()->addDay()->toDateString()]))->assertOk()->assertDontSee('Saldo pendiente')->assertDontSee('order-unpaid');
    }

    public function test_new_order_defaults_and_preserves_selected_delivery_date(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 26)->setTime(12, 0));
        \Livewire\Livewire::test(\App\Livewire\Pos::class)
            ->assertSet('dateInput', '2026-09-26')->assertSet('delivery_type', 'domicilio')
            ->set('customerForm.name', 'Cliente')->set('customerForm.phone', '9630001111')
            ->call('saveCustomer')->assertHasNoErrors()->assertSet('dateInput', '2026-09-26')
            ->set('dateInput', '2026-09-28')->set('timeInput', '14:00')->set('delivery_type', 'sucursal')
            ->call('confirmSchedule')->assertHasNoErrors()->call('editCustomer')->call('saveCustomer')
            ->assertSet('dateInput', '2026-09-28')->assertSet('delivery_type', 'sucursal');
    }
    public function test_orders_default_to_today_and_filter_inclusive_delivery_range(): void
    {
        $service = app(OrderService::class);
        $today = $service->create(array_replace($this->data(), ['scheduled_date'=>now()->toDateString(), 'customer_name'=>'Cliente hoy']));
        $tomorrow = $service->create(array_replace($this->data(), ['customer_name'=>'Cliente manana']));
        $later = $service->create(array_replace($this->data(), ['scheduled_date'=>now()->addDays(2)->toDateString(), 'customer_name'=>'Cliente despues']));
        $this->get('/pedidos')->assertOk()->assertSee('Cliente hoy')->assertDontSee('Cliente manana')->assertDontSee('Cliente despues');
        $query = http_build_query(['date_from'=>now()->toDateString(),'date_to'=>now()->addDay()->toDateString()]);
        $this->get('/pedidos?'.$query)->assertOk()->assertSee('Cliente hoy')->assertSee('Cliente manana')->assertDontSee('Cliente despues');
        $this->get('/pedidos?'.$query.'&q=manana')->assertOk()->assertSee('Cliente manana')->assertDontSee('Cliente hoy');
        $this->from('/pedidos')->get('/pedidos?'.http_build_query(['date_from'=>now()->addDay()->toDateString(),'date_to'=>now()->toDateString()]))->assertSessionHasErrors('date_to');
    }
    public function test_company_settings_are_validated_and_print_only_on_cash_receipts(): void
    {
        $this->get('/configuracion')->assertOk()->assertSee('Configuración general');
        $data=['business_name'=>'Empresa Prueba','legal_name'=>'Empresa Ejemplo SA','rfc'=>'AAA010101AAA','address'=>'Calle Empresa 123','phone'=>'9631112233','email'=>'empresa@example.com','website'=>'https://example.com','ticket_footer'=>'Vuelva pronto'];
        $this->post('/configuracion',$data)->assertSessionHasNoErrors()->assertRedirect(route('settings'));
        $this->assertDatabaseHas('company_settings',['id'=>1,'business_name'=>'Empresa Prueba']);
        $this->post('/configuracion',array_replace($data,['business_name'=>'','email'=>'invalid']))->assertSessionHasErrors(['business_name','email']);
        $order=app(OrderService::class)->create($this->data());
        $cashId=PrintArea::where('name','Caja')->value('id');
        foreach(PrintJob::where('order_id',$order->id)->get() as $job){
            if($job->print_area_id==$cashId){
                foreach($data as $value)$this->assertStringContainsString($value,$job->payload);
                foreach(explode("\n",$job->payload) as $line)$this->assertLessThanOrEqual(32,strlen($line));
            }else{$this->assertStringNotContainsString('Empresa Prueba',$job->payload);$this->assertStringNotContainsString('Calle Empresa',$job->payload);}
        }
        $before=PrintJob::where('order_id',$order->id)->pluck('payload','id')->all();
        $this->post('/configuracion',array_replace($data,['business_name'=>'Nombre actualizado','phone'=>'','ticket_footer'=>'']))->assertSessionHasNoErrors();
        $this->assertSame($before,PrintJob::where('order_id',$order->id)->pluck('payload','id')->all());
        auth()->user()->syncRoles(['Cajero']);auth()->user()->unsetRelation('roles')->unsetRelation('permissions');
        $this->get('/configuracion')->assertForbidden();
        $this->post('/configuracion',$data)->assertForbidden();
        $this->assertDatabaseHas('company_settings',['business_name'=>'Nombre actualizado']);
    }
}
