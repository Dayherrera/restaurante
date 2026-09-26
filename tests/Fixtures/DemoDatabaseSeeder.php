<?php

namespace Tests\Fixtures;

use App\Models\Category;
use App\Models\PrintArea;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DemoDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permissions = ['pos.sell', 'orders.view', 'orders.edit', 'orders.cancel', 'orders.reprint', 'production.override', 'cash.manage', 'dispatch.manage', 'catalog.manage', 'users.manage', 'reports.view', 'printing.manage'];
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $roles = ['Administrador' => $permissions, 'Cajero' => ['pos.sell', 'orders.view', 'orders.reprint', 'cash.manage'], 'Cocina' => ['orders.view', 'dispatch.manage'], 'Repartidor' => ['orders.view', 'dispatch.manage']];
        foreach ($roles as $name => $perms) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            if ($role->wasRecentlyCreated) {
                $role->syncPermissions($perms);
            }
        }
        if (! User::where('email', 'admin@magueyes.local')->exists()) {
            $password = env('POS_ADMIN_PASSWORD');
            if (! $password) {
                throw new \RuntimeException('Define POS_ADMIN_PASSWORD en .env antes de cargar datos.');
            }
            $user = User::create(['name' => 'Administrador', 'email' => 'admin@magueyes.local', 'password' => $password]);
            $user->assignRole('Administrador');
        }
        foreach (['Cocina', 'Barra', 'Caja'] as $name) {
            PrintArea::firstOrCreate(compact('name'));
        }
        foreach (['Charolas', 'Botanas', 'Bebidas'] as $name) {
            Category::firstOrCreate(compact('name'));
        }
        $demo = [['Charola Especial', 850, 'Charolas', 'Cocina', 'compuesto', 12, 10, 30], ['Charola Familiar', 550, 'Charolas', 'Cocina', 'compuesto', 6, 12, 40], ['Minilla de pescado', 120, 'Botanas', 'Cocina', 'simple', 0, 20, 60], ['Camarones al ajillo', 190, 'Botanas', 'Cocina', 'simple', 0, 15, 50], ['Guacamole', 85, 'Botanas', 'Cocina', 'simple', 0, 0, 0], ['Agua de jamaica 1 L', 55, 'Bebidas', 'Barra', 'simple', 0, 0, 0], ['Limonada mineral', 45, 'Bebidas', 'Barra', 'simple', 0, 0, 0], ['Refresco', 35, 'Bebidas', 'Barra', 'simple', 0, 0, 0]];
        foreach ($demo as [$name,$price,$category,$area,$type,$choices,$hour,$day]) {
            $p = Product::firstOrCreate(['name' => $name], ['price' => $price, 'category_id' => Category::where('name', $category)->value('id'), 'print_area_id' => PrintArea::where('name', $area)->value('id'), 'type' => $type, 'max_choices' => $choices, 'hourly_limit' => $hour, 'daily_limit' => $day]);
            if ($p->wasRecentlyCreated && $choices) {
                foreach (['Minilla', 'Camarón', 'Butifarra', 'Chiles rellenos', 'Guacamole', 'Queso fresco', 'Frijoles', 'Pico de gallo'] as $option) {
                    $p->options()->create(['option_name' => $option]);
                }
            }
        }
    }
}
