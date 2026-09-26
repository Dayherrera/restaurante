<?php

namespace Database\Seeders;

use App\Models\PrintArea;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
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
        $this->call(RealMenuSeeder::class);
        $this->call(MenuUxSeeder::class);
    }
}
