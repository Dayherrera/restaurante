<?php

use App\Http\Controllers\PosController as C;
use App\Http\Controllers\PrintAgentController;
use App\Http\Middleware\ActiveUser;
use App\Livewire\Pos;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::view('/login', 'login')->name('login');
    Route::post('/login', [C::class, 'login'])->middleware('throttle:10,1');
});
Route::middleware(['auth', ActiveUser::class])->group(function () {
    Route::get('/', function () {
        foreach (['pos.sell' => '/pos', 'dispatch.manage' => '/despacho', 'orders.view' => '/pedidos'] as $permission => $url) {
            if (auth()->user()->can($permission)) {
                return redirect($url);
            }
        } abort(403, 'No tienes permisos asignados.');
    });
    Route::post('/logout', [C::class, 'logout'])->name('logout');
    Route::get('/pos', Pos::class)->middleware(\App\Http\Middleware\RequireOpenCashShift::class)->name('pos');
    Route::get('/calendario', \App\Livewire\DeliveryCalendar::class)->name('calendar');
    Route::get('/pedidos', [C::class, 'orders'])->name('orders');
    Route::get('/pedidos/{order}', [C::class, 'show'])->name('orders.show');
    Route::post('/pedidos/{order}/pago', [C::class, 'pay']);
    Route::post('/pedidos/{order}/cancelar', [C::class, 'cancel']);
    Route::post('/pedidos/{order}/reimprimir', [C::class, 'reprint']);
    Route::post('/pedidos/{order}/editar', [C::class, 'editOrder']);
    Route::post('/pedidos/{order}/estado', [C::class, 'status']);
    Route::post('/pedidos/{order}/repartidor', [C::class, 'assign']);
    Route::post('/pedidos/{order}/liberar', [C::class, 'release']);
    Route::get('/despacho', [C::class, 'dispatch'])->name('dispatch');
    Route::get('/caja', [C::class, 'cash'])->name('cash');
    Route::post('/caja/abrir', [C::class, 'openCash']);
    Route::post('/caja/movimiento', [C::class, 'movement']);
    Route::post('/caja/cerrar', [C::class, 'closeCash']);
    Route::get('/catalogo', \App\Livewire\Catalog::class)->name('catalog');
    Route::get('/clientes', \App\Livewire\Customers::class)->name('customers');
    Route::get('/categorias', \App\Livewire\Categories::class)->name('categories');
    Route::get('/repartidores', [C::class, 'drivers'])->name('drivers');
    Route::post('/repartidores', [C::class, 'saveDriver']);
    Route::get('/configuracion', [\App\Http\Controllers\CompanySettingsController::class, 'edit'])->name('settings');
    Route::post('/configuracion', [\App\Http\Controllers\CompanySettingsController::class, 'update'])->name('settings.update');
    Route::get('/usuarios', [C::class, 'users'])->name('users');
    Route::post('/usuarios', [C::class, 'saveUser']);
    Route::post('/roles', [C::class, 'saveRole']);
    Route::get('/reportes', [\App\Http\Controllers\ReportController::class, 'index'])->name('reports');
    Route::get('/impresion', [C::class, 'printing'])->name('printing');
    Route::post('/impresion/{job}/reintentar', [C::class, 'retry']);
});
Route::prefix('print-agent')->middleware('throttle:120,1')->group(function () {
    Route::post('/claim', [PrintAgentController::class, 'claim']);
    Route::post('/{job}/ack',[PrintAgentController::class, 'ack']);
});
