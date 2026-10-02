<?php

namespace App\Http\Controllers;

use App\Models\CashShift;
use App\Models\Category;
use App\Models\DeliveryDriver;
use App\Models\Order;
use App\Models\PrintArea;
use App\Models\PrintJob;
use App\Models\Product;
use App\Models\User;
use App\Services\CashService;
use App\Services\OrderService;
use App\Services\TicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PosController extends Controller
{
    public function login(Request $r)
    {
        $data = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        $key = Str::lower($data['email']).'|'.$r->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Demasiados intentos. Espera un minuto.']);
        }
        if (! Auth::attempt($data + ['is_active' => true])) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'Credenciales incorrectas o usuario inactivo.']);
        }
        RateLimiter::clear($key);
        $r->session()->regenerate();

        return redirect()->intended('/');
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect('/login');
    }

    public function orders(Request $r)
    {
        Gate::authorize('orders.view');
        $r->merge([
            'date_from' => $r->input('date_from', now()->toDateString()),
            'date_to' => $r->input('date_to', now()->toDateString()),
        ]);
        $data = $r->validate(['date_from' => 'required|date_format:Y-m-d', 'date_to' => 'required|date_format:Y-m-d|after_or_equal:date_from', 'q' => 'nullable|string|max:120', 'agenda'=>'nullable|in:1']);
        $dateFrom = $data['date_from'];
        $dateTo = $data['date_to'];
        $orders = Order::with('driver')->when($r->input('agenda')==='1', fn($q)=>$q->whereDate('scheduled_date','>',today())->where('status','pendiente')->whereNull('production_released_at'), fn($q)=>$q->whereDate('scheduled_date', '>=', $dateFrom)->whereDate('scheduled_date', '<=', $dateTo))->when($r->q, fn ($q) => $q->where(fn ($w) => $w->where('order_number', 'like', '%'.$r->q.'%')->orWhere('customer_name', 'like', '%'.$r->q.'%')))->latest()->paginate(20)->withQueryString();

        return view('orders', compact('orders', 'dateFrom', 'dateTo'));
    }

    public function show(Order $order)
    {
        Gate::authorize('orders.view');

        return view('order', ['order' => $order->load('items', 'payments', 'driver'), 'drivers' => DeliveryDriver::where('is_active', true)->get(), 'paymentKey' => (string) Str::uuid()]);
    }

    public function pay(Request $r, Order $order)
    {
        $d = $r->validate(['method' => 'required|string', 'amount' => 'required|numeric', 'key' => 'required|uuid']);
        app(OrderService::class)->pay($order->id, $d['method'], $d['amount'], $d['key']);

        return back()->with('success', 'Abono registrado y comprobante enviado a impresión.');
    }

    public function cancel(Request $r, Order $order)
    {
        $d = $r->validate(['reason' => 'required|string|min:5|max:255', 'item_id' => 'nullable|integer']);
        app(OrderService::class)->cancel($order->id, $d['reason'], $d['item_id'] ?? null);

        return back()->with('success', 'Cancelación registrada. Revisa el reembolso en el detalle y realiza la devolución al cliente por los métodos indicados.');
    }

    public function reprint(Order $order)
    {
        Gate::authorize('orders.reprint');
        DB::transaction(function () use ($order) {
            app(TicketService::class)->queue($order, 'reimpresion');
            app(OrderService::class)->audit($order, 'reprint', []);
        });

        return back()->with('success', 'Reimpresión en cola.');
    }

    public function editOrder(Request $r, Order $order)
    {
        Gate::authorize('orders.edit');
        $d = $r->validate(['delivery_cost' => 'required|numeric|decimal:0,2|min:0|max:999999', 'notes' => 'array', 'notes.*' => 'nullable|string|max:1000']);
        DB::transaction(function () use ($d, $order) {
            $o = Order::lockForUpdate()->findOrFail($order->id);
            if (in_array($o->status, ['cancelado', 'entregado'])) {
                throw ValidationException::withMessages(['order' => 'El pedido ya no admite cambios.']);
            }
            $cost = $o->delivery_type === 'domicilio' ? OrderService::cents($d['delivery_cost']) : 0;
            $total = OrderService::cents($o->total) - OrderService::cents($o->delivery_cost) + $cost;
            if ($total < OrderService::cents($o->amount_paid)) {
                throw ValidationException::withMessages(['order' => 'El nuevo total es menor al importe pagado. Cancela y registra la devolución antes de rehacer el pedido.']);
            }
            foreach ($d['notes'] ?? [] as $id => $note) {
                $o->items()->whereKey($id)->where('is_cancelled', false)->update(['kitchen_notes' => $note]);
            }
            $o->update(['delivery_cost' => OrderService::money($cost), 'total' => OrderService::money($total), 'balance_due' => OrderService::money($total - OrderService::cents($o->amount_paid))]);
            app(OrderService::class)->audit($o, 'modified', $d);
            app(TicketService::class)->queue($o, 'modificacion');
        });

        return back()->with('success', 'Cambios registrados. Cocina recibe la modificación únicamente si el pedido ya fue liberado.');
    }

    public function release(Request $r, Order $order)
    {
        $data=$r->validate(['reason'=>'nullable|string|max:500']);
        app(OrderService::class)->release($order->id,$data['reason']??null);
        return back()->with('success','Pedido liberado. Comandas enviadas a producción.');
    }

    public function dispatch(Request $r)
    {
        Gate::authorize('dispatch.manage');
        $view=$r->validate(['view'=>'nullable|in:today,overdue,early'])['view']??'today';
        $base=Order::with('items','driver')->whereNotIn('status',['entregado','cancelado']);
        $overdue=(clone $base)->whereDate('scheduled_date','<',today())->count();
        $early=(clone $base)->whereDate('scheduled_date','>',today())->whereNotNull('production_released_at')->count();
        $orders=$base->when($view==='today',fn($q)=>$q->whereDate('scheduled_date',today()))
            ->when($view==='overdue',fn($q)=>$q->whereDate('scheduled_date','<',today()))
            ->when($view==='early',fn($q)=>$q->whereDate('scheduled_date','>',today())->whereNotNull('production_released_at'))
            ->orderBy('scheduled_date')->orderBy('scheduled_time')->get();
        return view('dispatch',compact('orders','view','overdue','early'));
    }

    public function status(Request $r, Order $order)
    {
        $r->validate(['status' => 'required|string']);
        app(OrderService::class)->status($order->id, $r->status);

        return back()->with('success', 'Estado actualizado.');
    }

    public function assign(Request $r, Order $order)
    {
        Gate::authorize('dispatch.manage');
        $d = $r->validate(['delivery_driver_id' => 'required|exists:delivery_drivers,id']);
        DB::transaction(function () use ($d, $order) {
            $o = Order::lockForUpdate()->findOrFail($order->id);
            if ($o->delivery_type !== 'domicilio' || in_array($o->status, ['entregado', 'cancelado'])) {
                throw ValidationException::withMessages(['driver' => 'Este pedido no admite asignación.']);
            }
            DeliveryDriver::where('is_active', true)->findOrFail($d['delivery_driver_id']);
            $o->update($d);
            app(OrderService::class)->audit($o, 'driver_assigned', $d);
        });

        return back()->with('success', 'Repartidor asignado.');
    }

    public function cash()
    {
        Gate::authorize('cash.manage');

        return view('cash', ['shift' => CashShift::where('user_id', auth()->id())->where('status', 'open')->first(), 'history' => CashShift::where('user_id', auth()->id())->where('status', 'closed')->latest()->limit(15)->get()]);
    }

    public function openCash(Request $r)
    {
        $d = $r->validate(['initial' => 'required|numeric|decimal:0,2|min:0|max:999999']);
        app(CashService::class)->open($d['initial']);

        return back()->with('success', 'Caja abierta.');
    }

    public function movement(Request $r)
    {
        Gate::authorize('cash.manage');
        $d = $r->validate(['type' => 'required|in:entrada,retiro', 'amount' => 'required|numeric|decimal:0,2|min:0.01|max:999999', 'reason' => 'required|string|min:3|max:255']);
        DB::transaction(function () use ($d) {
            $shift = app(OrderService::class)->shift();
            if ($d['type'] === 'retiro' && OrderService::cents($d['amount']) > OrderService::cents(app(CashService::class)->totals($shift)['efectivo']['expected'])) {
                throw ValidationException::withMessages(['cash' => 'El retiro supera el efectivo disponible.']);
            } DB::table('cash_movements')->insert($d + ['cash_shift_id' => $shift->id, 'user_id' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
        });

        return back()->with('success', 'Movimiento registrado.');
    }

    public function closeCash(Request $r)
    {
        $d = $r->validate(['efectivo' => 'required|numeric|decimal:0,2|min:0|max:9999999', 'transferencia' => 'required|numeric|decimal:0,2|min:0|max:9999999', 'tarjeta' => 'required|numeric|decimal:0,2|min:0|max:9999999']);
        app(CashService::class)->close($d);

        return back()->with('success', 'Caja cerrada. El arqueo ya está disponible.');
    }

    public function catalog(Request $r)
    {
        Gate::authorize('catalog.manage');

        return view('catalog', ['products' => Product::with('category', 'printArea')->orderBy('name')->get(), 'editing' => $r->edit ? Product::with('options')->findOrFail($r->edit) : null, 'categories' => Category::all(), 'areas' => PrintArea::all()]);
    }

    public function saveProduct(Request $r)
    {
        Gate::authorize('catalog.manage');
        $r->merge(\App\Support\UppercaseInput::fields($r->all(), ['name', 'description', 'options']));
        $d = $r->validate(['id' => 'nullable|exists:products,id', 'name' => 'required|string|max:150', 'price' => 'required|numeric|decimal:0,2|min:0|max:999999', 'category_id' => 'required|exists:categories,id', 'print_area_id' => 'required|exists:print_areas,id', 'type' => 'required|in:simple,compuesto', 'max_choices' => 'required|integer|min:0|max:100', 'hourly_limit' => 'required|integer|min:0|max:100000', 'daily_limit' => 'required|integer|min:0|max:100000', 'options' => 'nullable|string|max:5000', 'description' => 'nullable|string|max:3000']);
        $options = array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/', $d['options'] ?? '')))));
        if ($d['type'] === 'compuesto' && (! $options || $d['max_choices'] < 1)) {
            throw ValidationException::withMessages(['options' => 'Los productos compuestos necesitan opciones y al menos una elección.']);
        }
        foreach ($options as $option) {
            if (mb_strlen($option) > 100) {
                throw ValidationException::withMessages(['options' => 'Cada opción admite hasta 100 caracteres.']);
            }
        }
        DB::transaction(function () use ($d, $r, $options) {
            $id = $d['id'] ?? null;
            unset($d['id'],$d['options']);
            $d['is_active'] = $r->boolean('is_active');
            if ($d['type'] === 'simple') {
                $d['max_choices'] = 0;
            }
            $p = $id ? Product::lockForUpdate()->findOrFail($id) : new Product;
            $p->fill($d)->save();
            $p->options()->whereNotIn('option_name', $options)->delete();
            foreach ($options as $option) {
                $p->options()->firstOrCreate(['option_name' => $option]);
            }
        });

        return redirect('/catalogo')->with('success', 'Producto guardado.');
    }

    public function category(Request $r)
    {
        Gate::authorize('catalog.manage');
        $r->merge(\App\Support\UppercaseInput::fields($r->all(), ['name', 'description', 'options']));
        $d = $r->validate(['name' => 'required|string|max:100|unique:categories,name']);
        Category::create($d);

        return back()->with('success', 'Categoría creada.');
    }

    public function drivers()
    {
        Gate::authorize('dispatch.manage');

        return view('drivers', ['drivers' => DeliveryDriver::all()]);
    }

    public function saveDriver(Request $r)
    {
        Gate::authorize('dispatch.manage');
        $r->merge(\App\Support\UppercaseInput::fields($r->all(), ['name', 'description', 'options']));
        $d = $r->validate(['id' => 'nullable|exists:delivery_drivers,id', 'name' => 'required|string|max:100', 'phone' => 'nullable|string|max:25']);
        $id = $d['id'] ?? null;
        unset($d['id']);
        DeliveryDriver::updateOrCreate(['id' => $id], $d + ['is_external' => $r->boolean('is_external'), 'is_active' => $r->boolean('is_active')]);

        return back()->with('success', 'Repartidor guardado.');
    }

    public function users()
    {
        Gate::authorize('users.manage');

        return view('users', ['users' => User::with('roles')->get(), 'roles' => Role::with('permissions')->get(), 'permissions' => Permission::all()]);
    }

    public function saveUser(Request $r)
    {
        Gate::authorize('users.manage');
        $r->merge(\App\Support\UppercaseInput::fields($r->all(), ['name', 'description', 'options']));
        $d = $r->validate(['id' => 'nullable|exists:users,id', 'name' => 'required|string|max:100', 'email' => 'required|email|max:100', 'password' => 'nullable|string|min:10|max:100', 'role' => 'required|exists:roles,name']);
        if (User::where('email', $d['email'])->when($d['id'] ?? null, fn ($q, $id) => $q->where('id', '!=', $id))->exists()) {
            throw ValidationException::withMessages(['email' => 'El correo ya está registrado.']);
        }
        if (empty($d['id']) && empty($d['password'])) {
            throw ValidationException::withMessages(['password' => 'Define una contraseña de al menos 10 caracteres.']);
        }
        if (($d['id'] ?? null) == auth()->id() && (! $r->boolean('is_active') || $d['role'] !== 'Administrador')) {
            throw ValidationException::withMessages(['user' => 'No puedes desactivar o quitar tu propio rol administrador.']);
        }
        DB::transaction(function () use ($d, $r) {
            $u = isset($d['id']) ? User::findOrFail($d['id']) : new User;
            $u->name = $d['name'];
            $u->email = $d['email'];
            if (! empty($d['password'])) {
                $u->password = $d['password'];
            } $u->is_active = $r->boolean('is_active');
            $u->save();
            $u->syncRoles([$d['role']]);
        });

        return back()->with('success', 'Usuario guardado.');
    }

    public function saveRole(Request $r)
    {
        Gate::authorize('users.manage');
        $d = $r->validate(['name' => 'required|string|max:80', 'permissions' => 'array', 'permissions.*' => 'exists:permissions,name']);
        if ($d['name'] === 'Administrador') {
            throw ValidationException::withMessages(['role' => 'El rol Administrador conserva todos los permisos.']);
        }
        $role = Role::firstOrCreate(['name' => $d['name'], 'guard_name' => 'web']);
        $role->syncPermissions($d['permissions'] ?? []);

        return back()->with('success', 'Permisos guardados.');
    }

    public function reports(Request $r)
    {
        Gate::authorize('reports.view');
        $r->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);
        $from = $r->from ?? now()->toDateString();
        $to = $r->to ?? $from;
        $orders = Order::whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59']);
        $payments = DB::table('order_payments')->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])->selectRaw('payment_method, SUM(amount) as total')->groupBy('payment_method')->get();
        $refunds = DB::table('order_refunds')->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])->selectRaw('payment_method, SUM(amount) as total')->groupBy('payment_method')->get();
        $production = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')->whereBetween('scheduled_date', [$from, $to])->where('is_cancelled', false)->where('status', '!=', 'cancelado')->selectRaw('scheduled_date, SUBSTR(scheduled_time,1,2) as hour, product_name, SUM(quantity) as units')->groupBy('scheduled_date', 'hour', 'product_name')->orderBy('scheduled_date')->orderBy('hour')->get();

        return view('reports', ['from' => $from, 'to' => $to, 'sales' => (clone $orders)->sum('total'), 'balance' => (clone $orders)->sum('balance_due'), 'count' => (clone $orders)->count(), 'payments' => $payments, 'refunds' => $refunds, 'production' => $production, 'shifts' => CashShift::whereBetween('closed_at', [$from.' 00:00:00', $to.' 23:59:59'])->get()]);
    }

    public function printing()
    {
        Gate::authorize('printing.manage');

        return view('printing', ['jobs' => PrintJob::with([])->latest()->paginate(30), 'areas' => PrintArea::all()]);
    }

    public function retry(PrintJob $job)
    {
        Gate::authorize('printing.manage');
        DB::transaction(function () use ($job) {
            $job = PrintJob::lockForUpdate()->findOrFail($job->id);
            if ($job->print_area_id != \App\Models\PrintArea::where('name','Caja')->value('id') && !Order::findOrFail($job->order_id)->production_released_at) throw ValidationException::withMessages(['print'=>'Libera el pedido a cocina; no se puede reintentar una comanda sin autorización.']);
            if ($job->status === 'processing' && $job->leased_at > now()->subMinutes(2)->toDateTimeString()) {
                throw ValidationException::withMessages(['print' => 'El agente todavía procesa este trabajo.']);
            } $job->update(['status' => 'pending', 'lease_token' => null, 'leased_at' => null, 'attempts' => 0, 'error' => null]);
        });

        return back()->with('success', 'Trabajo listo para reintentar. Puede duplicarse si la impresora ya lo había recibido.');
    }
}
