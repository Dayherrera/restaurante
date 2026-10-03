<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PrintArea;
use App\Models\Product;
use App\Models\User;
use App\Services\CashService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Fixtures\DemoDatabaseSeeder;
use Tests\TestCase;
use ZipArchive;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 2)->setTime(10, 0));
        putenv('POS_ADMIN_PASSWORD=Testing-Password-123');
        $this->seed(DemoDatabaseSeeder::class);
        $this->actingAs(User::where('email', 'admin@magueyes.local')->firstOrFail());
        app(CashService::class)->open('100');
    }

    private function order(string $name = 'CLIENTE REPORTE'): Order
    {
        return app(OrderService::class)->create([
            'request_key'=>(string)Str::uuid(), 'customer_name'=>$name, 'customer_phone'=>'9631234567',
            'delivery_type'=>'sucursal', 'delivery_cost'=>0, 'scheduled_date'=>'2026-10-03', 'scheduled_time'=>'21:30',
            'items'=>[['product_id'=>Product::where('name','Minilla de pescado')->value('id'), 'quantity'=>2, 'options'=>[], 'notes'=>'SIN SAL']],
            'payments'=>[['method'=>'efectivo','amount'=>100]],
        ]);
    }

    public function test_all_reports_render_and_reject_invalid_filters_and_unauthorized_exports(): void
    {
        foreach (['summary','production','balances','payments'] as $type) {
            $this->get('/reportes?report='.$type)->assertOk()->assertSee('Exportar a Excel');
        }
        foreach (['from=2026-10-03&to=2026-10-01', 'report=bad', 'from=2026-10-01', 'method=bad', 'area=99999'] as $query) {
            $this->getJson('/reportes?'.$query)->assertUnprocessable();
        }
        auth()->user()->syncRoles(['Cajero']);
        auth()->user()->unsetRelation('roles')->unsetRelation('permissions');
        $this->get('/reportes')->assertForbidden();
        $this->get('/reportes?export=xlsx')->assertForbidden();
    }

    public function test_balances_include_old_orders_and_filter_by_delivery_not_registration(): void
    {
        $old = $this->order('ANTIGUO');
        $old->update(['created_at'=>now()->subMonth(), 'scheduled_date'=>'2026-10-01']);
        $this->order('FUTURO');
        $paid = $this->order('LIQUIDADO');
        app(OrderService::class)->pay($paid->id, 'efectivo', 140, (string)Str::uuid());
        $cancelled = $this->order('CANCELADO');
        app(OrderService::class)->cancel($cancelled->id,'Cancelación de prueba');
        $report = $this->get('/reportes?report=balances')->assertOk()->viewData('report');
        $this->assertSame(2, $report['stats']['Pedidos con saldo']);
        $this->assertSame('$280.00', $report['stats']['Saldo actual por cobrar']);
        $this->assertSame(['ANTIGUO','FUTURO'], array_column($report['tables'][0]['rows'],1));
        $report = $this->get('/reportes?report=balances&bucket=overdue')->assertOk()->viewData('report');
        $this->assertSame(['ANTIGUO'],array_column($report['tables'][0]['rows'],1));
        $report = $this->get('/reportes?report=balances&from=2026-10-03&to=2026-10-03')->assertOk()->viewData('report');
        $this->assertSame(['FUTURO'],array_column($report['tables'][0]['rows'],1));
    }

    public function test_production_uses_delivery_date_and_multiplies_choices_by_item_quantity(): void
    {
        $o = $this->order();
        $o->items->first()->update(['selected_options'=>[['group'=>'Botanas a elegir','name'=>'CARNE ASADA','quantity'=>4]]]);
        $done = $this->order('YA LISTO'); $done->update(['status'=>'listo']);
        $cancelled = $this->order('CANCELADO'); app(OrderService::class)->cancel($cancelled->id,'Cancelación de prueba');
        $today = $this->get('/reportes?report=production')->assertOk()->viewData('report');
        $this->assertSame(0,$today['stats']['Pedidos']);
        $query = '/reportes?report=production&from=2026-10-03&to=2026-10-03';
        $report = $this->get($query)->assertOk()->viewData('report');
        $this->assertSame(1,$report['stats']['Pedidos']);
        $this->assertSame(2,$report['tables'][0]['rows'][0][4]);
        $this->assertSame(8,$report['tables'][1]['rows'][0][5]);
        $this->assertSame('9:30 PM',$report['tables'][2]['rows'][0][2]);
        $this->assertSame($o->id,$report['tables'][2]['links'][0]);
        $all = $this->get($query.'&state=all')->assertOk()->viewData('report');
        $this->assertSame(2,$all['stats']['Pedidos']);
        $bar = PrintArea::where('name','Barra')->value('id');
        $empty = $this->get($query.'&area='.$bar)->assertOk()->viewData('report');
        $this->assertSame(0,$empty['stats']['Pedidos']);
    }

    public function test_payments_use_movement_dates_and_refunds_do_not_duplicate_order_totals(): void
    {
        $o = $this->order();
        $o->update(['created_at'=>now()->subMonth()]);
        $o->payments()->update(['created_at'=>now()->subDay()]);
        app(OrderService::class)->pay($o->id,'tarjeta',140,(string)Str::uuid());
        app(OrderService::class)->cancel($o->id,'Cancelación de prueba');
        $report = $this->get('/reportes?report=payments')->assertOk()->viewData('report');
        $this->assertSame('$140.00',$report['stats']['Cobrado']);
        $this->assertSame('$240.00',$report['stats']['Devuelto']);
        $this->assertSame('$-100.00',$report['stats']['Cobro neto']);
        $this->assertCount(3,$report['tables'][1]['rows']);
        $report = $this->get('/reportes?report=payments&method=tarjeta&cashier='.auth()->id())->assertOk()->viewData('report');
        $this->assertSame('$0.00',$report['stats']['Cobro neto']);
        $this->assertCount(2,$report['tables'][1]['rows']);
        $summary = $this->get('/reportes')->assertOk()->viewData('report');
        $this->assertSame(0,$summary['stats']['Pedidos vigentes registrados']);
        $this->assertSame('$0.00',$summary['stats']['Saldo global actual']);
    }

    public function test_excel_export_matches_filters_and_preserves_folio_phone_and_literal_text(): void
    {
        $this->order('=SUM(1,2)');
        $response = $this->get('/reportes?report=balances&bucket=future&export=xlsx')->assertOk();
        $response->assertHeader('Content-Type','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $path = $response->baseResponse->getFile()->getPathname();
        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path));
            $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
            $this->assertNotFalse(simplexml_load_string($sheet));
            $this->assertStringContainsString('0000001',$sheet);
            $this->assertStringContainsString('9631234567',$sheet);
            $this->assertStringContainsString('=SUM(1,2)',$sheet);
            $this->assertStringNotContainsString('<f>',$sheet);
            $this->assertStringContainsString('<v>140</v>',$sheet);
            $this->assertStringContainsString('Entregas futuras',$sheet);
            $zip->close();
        } finally { @unlink($path); }
    }
}
