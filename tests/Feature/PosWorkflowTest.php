<?php

namespace Tests\Feature;

use App\Livewire\Pos;
use App\Models\CashShift;
use App\Models\DeliveryDriver;
use App\Models\PrintJob;
use App\Models\Product;
use App\Models\User;
use App\Services\CashService;
use App\Services\OrderService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Fixtures\DemoDatabaseSeeder;
use Tests\TestCase;

class PosWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('POS_ADMIN_PASSWORD=Testing-Password-123');
        $this->seed(DemoDatabaseSeeder::class);
        $this->actingAs(User::where('email', 'admin@magueyes.local')->firstOrFail());
        app(CashService::class)->open('100.00');
    }

    private function data(array $replace = []): array
    {
        $p = Product::where('name', 'Minilla de pescado')->firstOrFail();

        return array_replace(['request_key' => (string) Str::uuid(), 'customer_name' => 'Cliente prueba', 'customer_phone' => '5551234567', 'delivery_type' => 'sucursal', 'delivery_address' => '', 'delivery_cost' => 0, 'scheduled_date' => now()->addDay()->toDateString(), 'scheduled_time' => '14:00', 'items' => [['product_id' => $p->id, 'quantity' => 2, 'notes' => 'Sin cebolla', 'options' => []]], 'payments' => [['method' => 'efectivo', 'amount' => 100], ['method' => 'transferencia', 'amount' => 20]], 'override' => false], $replace);
    }

    public function test_all_pages_render_for_admin(): void
    {
        foreach (['/pos', '/pedidos', '/despacho', '/caja', '/catalogo', '/repartidores', '/usuarios', '/reportes', '/impresion'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_split_payments_print_jobs_and_idempotency(): void
    {
        $data = $this->data();
        $o = app(OrderService::class)->create($data);
        $again = app(OrderService::class)->create($data);
        $this->assertSame($o->id, $again->id);
        $this->assertEquals('240.00', $o->total);
        $this->assertEquals('120.00', $o->balance_due);
        $this->assertCount(2, $o->payments);
        $this->assertEquals(0, PrintJob::count());
        $this->get('/pedidos/'.$o->id)->assertOk()->assertSee('Sin cebolla');
    }

    public function test_prices_are_always_read_from_database(): void
    {
        $d = $this->data();
        $d['items'][0]['unit_price'] = 0.01;
        $o = app(OrderService::class)->create($d);
        $this->assertEquals('240.00', $o->total);
    }

    public function test_exact_composite_choices_are_required(): void
    {
        $p = Product::where('type', 'compuesto')->firstOrFail();
        $d = $this->data(['items' => [['product_id' => $p->id, 'quantity' => 1, 'options' => [$p->options->first()->id => 11]]]]);
        $this->expectException(ValidationException::class);
        app(OrderService::class)->create($d);
    }

    public function test_valid_composite_is_saved_with_choice_snapshot(): void
    {
        $p = Product::where('type', 'compuesto')->firstOrFail();
        $d = $this->data(['items' => [['product_id' => $p->id, 'quantity' => 1, 'options' => [$p->options->first()->id => 12]]]]);
        $o = app(OrderService::class)->create($d);
        $this->assertEquals(12, $o->items->first()->selected_options[0]['quantity']);
    }

    public function test_invalid_option_cannot_be_injected(): void
    {
        $p = Product::where('type', 'compuesto')->firstOrFail();
        $this->expectException(ValidationException::class);
        app(OrderService::class)->create($this->data(['items' => [['product_id' => $p->id, 'quantity' => 1, 'options' => [99999 => 12]]]]));
    }

    public function test_daily_limit_cannot_be_overridden(): void
    {
        $p = Product::where('name', 'Minilla de pescado')->first();
        $p->update(['daily_limit' => 1]);
        $this->expectException(ValidationException::class);
        app(OrderService::class)->create($this->data(['override' => true]));
    }

    public function test_hourly_capacity_aggregates_duplicate_lines(): void
    {
        $p = Product::where('name', 'Minilla de pescado')->first();
        $p->update(['hourly_limit' => 3]);
        $d = $this->data();
        $d['items'][] = $d['items'][0];
        $this->expectException(ValidationException::class);
        app(OrderService::class)->create($d);
    }

    public function test_authorized_hourly_override_works(): void
    {
        Product::where('name', 'Minilla de pescado')->update(['hourly_limit' => 1]);
        $o = app(OrderService::class)->create($this->data(['override' => true]));
        $this->assertNotNull($o->id);
    }

    public function test_payment_is_idempotent_and_cannot_overpay(): void
    {
        $o = app(OrderService::class)->create($this->data());
        $key = (string) Str::uuid();
        app(OrderService::class)->pay($o->id, 'tarjeta', 120, $key);
        app(OrderService::class)->pay($o->id, 'tarjeta', 120, $key);
        $this->assertEquals(0, $o->fresh()->balance_due);
        $this->assertEquals(3, $o->payments()->count());
        $this->expectException(ValidationException::class);
        app(OrderService::class)->pay($o->id, 'efectivo', 1, (string) Str::uuid());
    }

    public function test_full_cancellation_refunds_and_blind_close_balances(): void
    {
        $o = app(OrderService::class)->create($this->data());
        app(OrderService::class)->cancel($o->id, 'Cancelado por cliente');
        $o->refresh();
        $this->assertSame('cancelado', $o->status);
        $this->assertEquals(0, $o->total);
        $this->assertEquals(0, $o->amount_paid);
        $this->assertDatabaseHas('order_refunds', ['payment_method' => 'efectivo', 'amount' => 100]);
        $this->get('/caja')->assertDontSee('Esperado');
        $s = app(CashService::class)->close(['efectivo' => 100, 'transferencia' => 0, 'tarjeta' => 0]);
        $this->assertEquals(0, $s->closing_summary['efectivo']['difference']);
        $this->get('/caja')->assertSee('Esperado');
    }

    public function test_partial_cancellation_preserves_remaining_total(): void
    {
        $d = $this->data();
        $p = Product::where('name', 'Refresco')->first();
        $d['items'][] = ['product_id' => $p->id, 'quantity' => 1, 'options' => []];
        $o = app(OrderService::class)->create($d);
        app(OrderService::class)->cancel($o->id, 'Quitar minilla completa', $o->items->first()->id);
        $this->assertEquals(35, $o->fresh()->total);
        $this->assertEquals(35, $o->fresh()->amount_paid);
        $this->assertEquals('pendiente', $o->fresh()->status);
    }

    public function test_dispatch_requires_payment_and_driver(): void
    {
        $driver = DeliveryDriver::create(['name' => 'Repartidor prueba', 'is_active' => true]);
        $o = app(OrderService::class)->create($this->data(['delivery_driver_id' => $driver->id, 'delivery_type' => 'domicilio', 'delivery_address' => 'Calle Uno 25', 'delivery_cost' => 50]));
        $o->update(['delivery_driver_id' => null]); // Legacy orders may still have no driver.
        $service = app(OrderService::class);
        $service->release($o->id, 'Preparación anticipada de prueba');
        $service->status($o->id, 'en_preparacion');
        $service->status($o->id, 'listo');
        try {
            $service->status($o->id, 'en_ruta');
            $this->fail('Requiere repartidor');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('repartidor', $e->getMessage());
        }
        $driver = DeliveryDriver::create(['name' => 'Repartidor prueba']);
        $o->update(['delivery_driver_id' => $driver->id]);
        $service->status($o->id, 'en_ruta');
        $service->pay($o->id, 'efectivo', 170, (string) Str::uuid());
        $service->status($o->id, 'entregado');
        $this->assertSame('entregado', $o->fresh()->status);
    }

    public function test_shift_is_required_and_cannot_be_opened_twice(): void
    {
        $this->post('/caja/abrir', ['initial' => 10])->assertSessionHasErrors();
        $this->assertEquals(1, CashShift::count());
        app(CashService::class)->close(['efectivo' => 100, 'transferencia' => 0, 'tarjeta' => 0]);
        $this->expectException(ValidationException::class);
        app(OrderService::class)->create($this->data());
    }

    public function test_cashier_cannot_modify_catalog_cancel_or_override(): void
    {
        $u = User::factory()->create();
        $u->assignRole('Cajero');
        $this->actingAs($u);
        $this->get('/catalogo')->assertForbidden();
        $this->post('/roles', ['name' => 'Administrador'])->assertForbidden();
        app(CashService::class)->open('0');
        Product::where('name', 'Minilla de pescado')->update(['hourly_limit' => 1]);
        $this->expectException(AuthorizationException::class);
        app(OrderService::class)->create($this->data(['override' => true]));
    }

    public function test_inactive_user_is_logged_out(): void
    {
        $u = auth()->user();
        $u->is_active = false;
        $u->save();
        $this->get('/pos')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_print_agent_authentication_claim_ack_and_expired_lease(): void
    {
        config(['pos.print_token' => 'test-token']);
        $o = app(OrderService::class)->create($this->data());
        app(OrderService::class)->release($o->id, 'Prueba de impresión autorizada');
        $this->postJson('/print-agent/claim', ['areas' => [1]])->assertUnauthorized();
        $response = $this->withToken('test-token')->postJson('/print-agent/claim', ['areas' => [1]])->assertOk();
        $job = $response->json('job');
        $this->assertNotNull($job);
        $this->assertStringContainsString('CHAROLAS', base64_decode($job['payload_base64']));
        $this->withToken('test-token')->postJson('/print-agent/'.$job['id'].'/ack', ['lease_token' => (string) Str::uuid(), 'success' => true])->assertStatus(409);
        $this->withToken('test-token')->postJson('/print-agent/'.$job['id'].'/ack', ['lease_token' => $job['lease_token'], 'success' => true])->assertOk();
        $this->assertDatabaseHas('print_jobs', ['id' => $job['id'], 'status' => 'printed']);
    }

    public function test_livewire_add_remove_and_submit(): void
    {
        $p = Product::where('name', 'Refresco')->first();
        Livewire::test(Pos::class)->set('customerForm.name', 'Cliente Livewire')->set('customerForm.phone', '5555555555')->call('saveCustomer')->set('dateInput', now()->addDay()->toDateString())->set('timeInput', '14:00')->set('delivery_type', 'sucursal')->call('confirmSchedule')->call('add', $p->id)->assertSet('items.0.product_id', $p->id)->set('payments.efectivo', 35)->call('save')->assertHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('orders', ['customer_name' => 'CLIENTE LIVEWIRE', 'total' => 35, 'balance_due' => 0]);
    }

    public function test_login_throttling_and_guest_protection(): void
    {
        auth()->logout();
        $this->get('/pos')->assertRedirect('/login');
        for ($i = 0; $i < 6; $i++) {
            $this->post('/login', ['email' => 'bad@example.test', 'password' => 'bad'])->assertSessionHasErrors();
        }
    }
}
