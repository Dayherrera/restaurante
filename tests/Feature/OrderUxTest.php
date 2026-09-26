<?php

namespace Tests\Feature;

use App\Livewire\Catalog;
use App\Livewire\Pos;
use App\Models\Category;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\CashService;
use App\Services\CustomerService;
use App\Services\ModifierService;
use App\Services\OrderService;
use Database\Seeders\MenuUxSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class OrderUxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('POS_ADMIN_PASSWORD=Testing-Password-123');
        $this->seed();
        $this->seed(MenuUxSeeder::class);
        $this->actingAs(User::where('email', 'admin@magueyes.local')->first());
    }

    private function pos()
    {
        return Livewire::test(Pos::class)->set('customerForm.name', 'Cliente UX')->set('customerForm.phone', '9631234567')->call('saveCustomer')->assertHasNoErrors()->set('dateInput', now()->addDay()->toDateString())->set('timeInput', '14:00')->call('confirmSchedule')->assertHasNoErrors();
    }

    public function test_menu_conversion_preserves_prices_and_is_idempotent()
    {
        $before = Product::pluck('price', 'id')->all();
        $this->seed(MenuUxSeeder::class);
        $this->assertSame($before, Product::pluck('price', 'id')->all());
        $this->assertSame(45, Product::whereHas('category', fn ($q) => $q->where('visible_in_pos', true))->count());
        foreach (Product::where('name', 'like', 'Charola especial%')->get() as $p) {
            $g = $p->modifierGroups->sole();
            $this->assertTrue($g->required);
            $this->assertEquals(12, $g->max_choices);
            $this->assertCount(18, $g->options);
            foreach ($g->options as $o) {
                $this->assertFalse($o->category->visible_in_pos);
                $this->assertEquals(Product::where('name', 'Orden extra — '.$o->name)->value('price') ?? 0, $o->price);
                $this->assertDoesNotMatchRegularExpression('/camar|minilla|macabil|aguachile|pescado/iu', $o->name);
            }
        }
    }

    public function test_customer_identity_and_address_rules()
    {
        $s = app(CustomerService::class);
        $c = $s->save(array_replace(CustomerService::blank(), ['name' => 'Ana', 'phone' => '+52 963 123 4567']));
        $this->assertEquals('9631234567', $c->phone);
        try {
            $s->requireAddress($c);
            $this->fail('Address required');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
        $this->expectException(ValidationException::class);
        $s->save(array_replace(CustomerService::blank(), ['name' => 'Duplicado', 'phone' => '9631234567']));
    }

    public function test_customer_and_schedule_are_required_and_capacity_checked_on_increment()
    {
        $p = Product::where('name', 'Plato de carnes — 2 personas')->first();
        $p->update(['daily_limit' => 1]);
        Livewire::test(Pos::class)->call('add', $p->id)->assertHasErrors('customer')->assertSet('items', []);
        $this->pos()->call('add', $p->id)->assertHasNoErrors()->call('quantity', 0, 1)->assertHasErrors()->assertSet('items.0.quantity', 1);
    }

    public function test_rib_eye_requires_term_allows_no_garnishes_and_keeps_price()
    {
        app(CashService::class)->open('0');
        $p = Product::where('name', 'Rib Eye (prueba)')->first();
        $g = $p->modifierGroups->where('name', 'Término')->first();
        $o = $g->options->first();
        $this->pos()->call('add', $p->id)->call('confirmModifiers')->assertHasErrors('modifiers')->call('option', $g->id, $o->id, 1)->call('confirmModifiers')->assertHasNoErrors()->call('save')->assertHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('orders', ['total' => 295]);
        $this->assertDatabaseHas('order_items', ['unit_price' => 295]);
        $this->assertStringContainsString('Término', OrderItem::first()->selected_options[0]['group']);
    }

    public function test_optional_group_maximum_and_foreign_options_are_enforced()
    {
        $p = Product::where('name', 'Rib Eye (prueba)')->first();
        $term = $p->modifierGroups->where('name', 'Término')->first();
        $g = $p->modifierGroups->where('name', 'Guarniciones')->first();
        $s = app(ModifierService::class);
        $base = [$term->id => [$term->options->first()->id => 1]];
        $this->assertCount(1, $s->snapshot($p, $base));
        foreach ([[$g->options->first()->id => 3], [$term->options->first()->id => 1]] as $invalid) {
            try {
                $s->snapshot($p, $base + [$g->id => $invalid]);
                $this->fail('Invalid selection accepted');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('modifiers', $e->errors());
            }
        }
    }

    public function test_catalog_inline_category_preserves_product_and_renders_pages()
    {
        Livewire::test(Catalog::class)->call('create')->set('form.name', 'Producto pendiente')->set('newCategoryName', 'Nueva categoría UX')->call('createCategory')->assertHasNoErrors()->assertSet('form.name', 'Producto pendiente')->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('products', ['name' => 'Producto pendiente', 'category_id' => Category::where('name', 'Nueva categoría UX')->value('id')]);
        foreach (['/catalogo', '/categorias', '/clientes'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_search_resets_stale_client_and_only_prefills_phone()
    {
        $c = app(CustomerService::class)->save(array_replace(CustomerService::blank(), ['name' => 'Ana', 'phone' => '9639998888', 'street' => 'Centro']));
        Livewire::test(Pos::class)->call('selectCustomer', $c->id)->assertSet('customerForm.street', 'Centro')->set('customerSearch', '963 000 1111')->assertSet('customerId', null)->assertSet('customerForm.name', '')->assertSet('customerForm.street', '')->assertSet('customerForm.phone', '9630001111')->assertSet('customerForm.city', 'COMITÁN DE DOMÍNGUEZ');
    }

    public function test_hidden_options_cannot_be_sold_directly()
    {
        $p = Product::whereHas('category', fn ($q) => $q->where('visible_in_pos', false))->first();
        $this->expectException(ValidationException::class);
        app(OrderService::class)->previewCapacity([['product_id' => $p->id, 'quantity' => 1]], now()->addDay()->toDateString(), '14:00');
    }

    public function test_catalog_saves_independent_group_limits()
    {
        $p = Product::where('name', 'Rib Eye (prueba)')->first();
        Livewire::test(Catalog::class)->call('edit', $p->id)->set('groups.1.max_choices', 1)->call('save')->assertHasNoErrors();
        $this->assertEquals(1,$p->modifierGroups()->where('name','Guarniciones')->value('max_choices'));
        $this->assertTrue($p->modifierGroups()->where('name','Término')->first()->required);
    }
}
