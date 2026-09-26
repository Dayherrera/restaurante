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
        $this->assertSame('1', $a->order_number);
        $this->assertSame($a->id, $s->create($data)->id);
        DB::beginTransaction();
        try {
            $this->assertSame('2', $s->create($this->data())->order_number);
        } finally {
            DB::rollBack();
        }
        $this->assertSame('2', $s->create($this->data())->order_number);
    }

    public function test_receipt_layout_and_kitchen_dispatch_only_show_choices_and_notes()
    {
        $p = Product::where('name', 'Rib Eye (prueba)')->first();
        $p->update(['description' => 'DESCRIPCION QUE NO DEBE IMPRIMIRSE']);
        $o = app(OrderService::class)->create($this->data());
        $jobs = PrintJob::where('order_id', $o->id)->get();
        $this->assertCount(2, $jobs);
        foreach ($jobs as $job) {
            $this->assertStringNotContainsString('DESCRIPCION QUE NO DEBE IMPRIMIRSE', $job->payload);
            $this->assertStringContainsString('Termino medio', $job->payload);
            $this->assertStringContainsString('Sin sal; preparar separado', $job->payload);
            $this->assertStringContainsString('FOLIO: 1', $job->payload);
            foreach (explode("\n", $job->payload) as $line) {
                $this->assertLessThanOrEqual(32, strlen($line));
            }
        }
        $cash = $jobs->firstWhere('print_area_id', PrintArea::where('name', 'Caja')->value('id'))->payload;
        $this->assertStringContainsString('1 x $295.00', $cash);
        $this->assertStringContainsString('SALDO PENDIENTE', $cash);
        $this->assertStringContainsString('$195.00', $cash);
        $this->get('/despacho')->assertOk()->assertSee('Término medio')->assertSee('Sin sal; preparar separado')->assertDontSee('DESCRIPCION QUE NO DEBE IMPRIMIRSE');
        app(TicketService::class)->queue($o, 'cancelacion', [$o->items->first()->id]);
        $this->assertStringContainsString('MOVIMIENTO PARCIAL', PrintJob::where('print_area_id', PrintArea::where('name', 'Caja')->value('id'))->latest('id')->first()->payload);
    }
}
