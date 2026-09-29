<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Services\CashService;
class PosCashEntryTest extends TestCase
{
 use RefreshDatabase;
 public function test_pos_requires_the_current_users_open_shift(): void {
  putenv('POS_ADMIN_PASSWORD=Testing-Password-123');$this->seed();
  $admin=User::first();$this->actingAs($admin);
  $this->get('/pos')->assertRedirect('/caja')->assertSessionHas('notice');
  $this->get('/caja')->assertOk()->assertSee('Abrir caja');
  app(CashService::class)->open('0');$this->get('/pos')->assertOk();
  $cashier=User::create(['name'=>'Cajero prueba','email'=>'cash-entry@example.test','password'=>'Testing-Password-123']);$cashier->assignRole('Cajero');
  $this->actingAs($cashier)->get('/pos')->assertRedirect('/caja');
  app(CashService::class)->open('0');$this->get('/pos')->assertOk();
 }
}
