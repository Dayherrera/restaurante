<?php

namespace Tests\Feature;

use App\Models\PrintJob;
use App\Models\Product;
use App\Models\User;
use App\Services\CashService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RealMenuTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_menu_prices_options_and_repeated_seeding(): void
    {
        putenv('POS_ADMIN_PASSWORD=Testing-Password-123');
        $this->seed();
        $this->seed();
        $this->assertEquals(72, Product::count());
        $expected = ['Charola de carnes — 4 personas' => 500, 'Charola de carnes — 8 personas' => 1000, 'Charola La Comiteca — 4 personas' => 500, 'Charola La Comiteca — 8 personas' => 1000, 'Charola mixta — 4 personas' => 650, 'Charola mixta — 8 personas' => 1300, 'Charola de mariscos — 4 personas' => 750, 'Charola de mariscos — 8 personas' => 1500, 'Plato de carnes — 2 personas' => 250, 'Plato La Comiteca — 2 personas' => 250, 'Plato mixto — 2 personas' => 300, 'Plato de mariscos — 2 personas' => 350, 'Charola especial — 6 personas' => 800, 'Charola especial — 12 personas' => 1600];
        foreach ($expected as $name => $price) {
            $this->assertDatabaseHas('products', compact('name', 'price'));
        }
        $this->assertEquals(30, Product::where('name', 'like', 'Orden extra%')->count());
        foreach (Product::where('name', 'like', 'Charola especial%')->get() as $p) {
            $this->assertEquals(12, $p->max_choices);
            $this->assertCount(18, $p->options);
            foreach ($p->options as $o) {
                $this->assertDoesNotMatchRegularExpression('/camar|minilla|macabil|aguachile|pescado/iu', $o->option_name);
            }
        }
        $this->assertEquals(0, Product::sum('daily_limit'));
        $this->assertDatabaseMissing('products', ['name' => 'Refresco']);
        $this->actingAs(User::where('email', 'admin@magueyes.local')->first());
        $this->get('/pos')->assertOk()->assertSee('Buscar cliente por nombre o teléfono')->assertDontSee('INDIVIDUAL');
    }

    public function test_fixed_composition_is_saved_and_printed_from_snapshot(): void
    {
        putenv('POS_ADMIN_PASSWORD=Testing-Password-123');
        $this->seed();
        $this->actingAs(User::where('email', 'admin@magueyes.local')->first());
        app(CashService::class)->open('0');
        $p = Product::where('name', 'Plato La Comiteca — 2 personas')->firstOrFail();
        $order = app(OrderService::class)->create(['request_key' => (string) Str::uuid(), 'customer_name' => 'Prueba', 'customer_phone' => '0000000000', 'delivery_type' => 'sucursal', 'delivery_cost' => 0, 'scheduled_date' => now()->addDay()->toDateString(), 'scheduled_time' => '14:00', 'items' => [['product_id' => $p->id, 'quantity' => 1, 'options' => []]], 'payments' => []]);
        $this->assertEquals(250, $order->total);
        $this->assertStringContainsString('Quesillo', $order->items->first()->product_description);
        $this->assertStringContainsString('Quesillo', PrintJob::first()->payload);
        $p->update(['description' => 'Descripción cambiada']);
        $this->assertStringContainsString('Quesillo', $order->items()->first()->product_description);
        $this->get('/pedidos/'.$order->id)->assertOk()->assertSee('Quesillo');
        $this->get('/despacho')->assertOk()->assertSee('Quesillo');
    }
}
