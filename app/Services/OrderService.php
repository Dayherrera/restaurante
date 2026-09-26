<?php

namespace App\Services;

use App\Models\CashShift;
use App\Models\Customer;
use App\Models\DeliveryDriver;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderService
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['order' => $message]);
    }

    public static function cents(mixed $value): int
    {
        return (int) round((float) $value * 100);
    }

    public static function money(int $value): string
    {
        return number_format($value / 100, 2, '.', '');
    }

    public function shift(): CashShift
    {
        $shift = CashShift::where('user_id', auth()->id())->where('status', 'open')->lockForUpdate()->first();
        if (! $shift) {
            $this->fail('Abre tu caja antes de registrar operaciones.');
        }

        return $shift;
    }

    public function capacity(Product $product, string $date, string $time): array
    {
        $base = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')->where('product_id', $product->id)->where('is_cancelled', false)->where('status', '!=', 'cancelado')->where('scheduled_date', $date);
        $day = (int) (clone $base)->lockForUpdate()->get(['order_items.quantity'])->sum('quantity');
        $hour = substr($time, 0, 2);
        $hourly = (int) (clone $base)->whereBetween('scheduled_time', [$hour.':00:00', $hour.':59:59'])->lockForUpdate()->get(['order_items.quantity'])->sum('quantity');

        return ['day' => $day, 'hour' => $hourly];
    }

    public function create(array $data): Order
    {
        Gate::authorize('pos.sell');
        Validator::make($data, [
            'request_key' => 'required|uuid', 'customer_name' => 'required|string|max:120', 'customer_phone' => 'required|string|max:25',
            'delivery_type' => 'required|in:sucursal,domicilio', 'delivery_address' => 'nullable|required_if:delivery_type,domicilio|string|max:500',
            'delivery_cost' => 'required|numeric|decimal:0,2|min:0|max:999999', 'scheduled_date' => 'required|date_format:Y-m-d|after_or_equal:today', 'scheduled_time' => 'required|date_format:H:i',
            'items' => 'required|array|min:1|max:100', 'items.*.product_id' => 'required|integer', 'items.*.quantity' => 'required|integer|min:1|max:1000',
            'items.*.notes' => 'nullable|string|max:1000', 'items.*.options' => 'array', 'items.*.options.*' => 'integer|min:0|max:1000',
            'payments' => 'array', 'payments.*.method' => 'required|in:efectivo,transferencia,tarjeta', 'payments.*.amount' => 'required|numeric|decimal:0,2|min:0|max:999999',
            'override' => 'boolean', 'customer_id' => 'nullable|exists:customers,id', 'delivery_driver_id' => 'nullable|exists:delivery_drivers,id', 'items.*.modifiers' => 'array',
        ])->validate();

        if (! empty($data['customer_id'])) {
            $customer = Customer::findOrFail($data['customer_id']);
            if ($data['delivery_type'] === 'domicilio') {
                app(CustomerService::class)->requireAddress($customer);
            }
            $data['customer_name'] = $customer->name;
            $data['customer_phone'] = $customer->phone;
            $data['delivery_address'] = $customer->address();
        }
        if (! empty($data['delivery_driver_id'])) {
            Gate::authorize('dispatch.manage');
            if ($data['delivery_type'] !== 'domicilio') {
                $this->fail('Solo puedes asignar repartidor a entregas a domicilio.');
            }
            DeliveryDriver::where('is_active', true)->findOrFail($data['delivery_driver_id']);
        }

        return DB::transaction(function () use ($data) {
            User::whereKey(auth()->id())->lockForUpdate()->firstOrFail();
            if ($existing = Order::where('request_key', $data['request_key'])->first()) {
                return $existing;
            }
            $shift = $this->shift();
            $products = Product::whereIn('id', array_column($data['items'], 'product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $quantities = [];
            foreach ($data['items'] as $item) {
                $quantities[$item['product_id']] = ($quantities[$item['product_id']] ?? 0) + $item['quantity'];
            }
            $this->assertCapacity($products, $quantities, $data['scheduled_date'], $data['scheduled_time'], ! empty($data['override']));
            $total = $data['delivery_type'] === 'domicilio' ? self::cents($data['delivery_cost']) : 0;
            $rows = [];
            foreach ($data['items'] as $item) {
                $product = $products[$item['product_id']];
                if ($product->modifierGroups()->exists()) {
                    if (array_sum($item['options'] ?? []) > 0) {
                        $this->fail('Este producto requiere seleccionar sus grupos de modificadores.');
                    }
                    $selected = app(ModifierService::class)->snapshot($product, $item['modifiers'] ?? []);
                } else {
                    if (! empty($item['modifiers'])) {
                        $this->fail('Este producto no admite esos modificadores.');
                    }
                    $selected = [];
                    $count = 0;
                    $options = $product->options->keyBy('id');
                    foreach ($item['options'] ?? [] as $optionId => $qty) {
                        if (! $qty) {
                            continue;
                        }
                        if (! $options->has($optionId) || $product->type !== 'compuesto') {
                            $this->fail('Selección de botanas inválida.');
                        }
                        $selected[] = ['name' => $options[$optionId]->option_name, 'quantity' => (int) $qty];
                        $count += $qty;
                    }
                    if ($product->type === 'compuesto' && $count !== $product->max_choices) {
                        $this->fail($product->name.' requiere exactamente '.$product->max_choices.' elecciones por charola.');
                    }
                }
                $total += self::cents($product->price) * $item['quantity'];
                $rows[] = ['product_id' => $product->id, 'product_name' => $product->name, 'product_description' => $product->description, 'print_area_id' => $product->print_area_id, 'quantity' => $item['quantity'], 'unit_price' => $product->price, 'selected_options' => $selected, 'kitchen_notes' => $item['notes'] ?? ''];
            }
            if ($total > 9999999999) {
                $this->fail('El total supera el máximo permitido por pedido.');
            }
            $paid = array_sum(array_map(fn ($p) => self::cents($p['amount']), $data['payments']));
            if ($paid > $total) {
                $this->fail('El pago aplicado supera el total. Registra únicamente el importe cobrado, sin incluir el cambio.');
            }
            $order = Order::create(collect($data)->only(['customer_id', 'delivery_driver_id', 'request_key', 'customer_name', 'customer_phone', 'delivery_type', 'delivery_address', 'scheduled_date', 'scheduled_time'])->all() + [
                'order_number' => 'MAG-'.strtoupper(substr(str_replace('-', '', $data['request_key']), 0, 16)), 'user_id' => auth()->id(), 'cash_shift_id' => $shift->id,
                'delivery_cost' => self::money($data['delivery_type'] === 'domicilio' ? self::cents($data['delivery_cost']) : 0), 'total' => self::money($total), 'amount_paid' => self::money($paid), 'balance_due' => self::money($total - $paid), 'status' => 'pendiente',
            ]);
            $order->items()->createMany($rows);
            foreach ($data['payments'] as $p) {
                if (self::cents($p['amount']) > 0) {
                    $order->payments()->create(['request_key' => Str::uuid(), 'cash_shift_id' => $shift->id, 'payment_method' => $p['method'], 'amount' => self::money(self::cents($p['amount'])), 'is_advance' => $paid < $total]);
                }
            }
            $this->audit($order, 'created', ['override' => ! empty($data['override'])]);
            app(TicketService::class)->queue($order, $paid < $total ? 'anticipo' : 'comanda');

            return $order;
        }, 3);
    }

    public function previewCapacity(array $items, string $date, string $time, bool $override = false): void
    {
        Gate::authorize('pos.sell');
        Validator::make(compact('items', 'date', 'time'), ['date' => 'required|date_format:Y-m-d|after_or_equal:today', 'time' => 'required|date_format:H:i', 'items' => 'array', 'items.*.product_id' => 'required|integer', 'items.*.quantity' => 'required|integer|min:1|max:1000'])->validate();
        DB::transaction(function () use ($items, $date, $time, $override) {
            $products = Product::whereIn('id', array_column($items, 'product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $quantities = [];
            foreach ($items as $item) {
                $quantities[$item['product_id']] = ($quantities[$item['product_id']] ?? 0) + $item['quantity'];
            }
            $this->assertCapacity($products, $quantities, $date, $time, $override);
        }, 3);
    }

    private function assertCapacity($products, array $quantities, string $date, string $time, bool $override): void
    {
        foreach ($quantities as $id => $qty) {
            $product = $products->get($id);
            if (! $product || ! $product->is_active || ! $product->category->visible_in_pos) {
                $this->fail('Un producto no está disponible.');
            }
            $capacity = $this->capacity($product, $date, $time);
            if ($product->daily_limit && $product->daily_limit < $capacity['day'] + $qty) {
                $this->fail('Límite diario agotado para '.$product->name.'. Selecciona otro día.');
            }
            if ($product->hourly_limit && $product->hourly_limit < $capacity['hour'] + $qty) {
                if (! $override) {
                    $suggestion = $this->suggest($products, $quantities, $date, $time);
                    $this->fail('Capacidad horaria excedida: '.$product->name.'. '.$suggestion.' Puedes autorizar la sobrecarga con el permiso correspondiente.');
                }
                Gate::authorize('production.override');
            }
        }
    }

    private function suggest($products, array $quantities, string $date, string $time): string
    {
        $slot = Carbon::parse($date.' '.$time)->startOfHour();
        for ($n = 0; $n < 168; $n++) {
            $slot->addHour();
            $fits = true;
            foreach ($quantities as $id => $qty) {
                $p = $products[$id];
                $c = $this->capacity($p, $slot->toDateString(), $slot->format('H:i'));
                if (($p->daily_limit && $p->daily_limit < $c['day'] + $qty) || ($p->hourly_limit && $p->hourly_limit < $c['hour'] + $qty)) {
                    $fits = false;
                    break;
                }
            }
            if ($fits) {
                return 'Siguiente espacio: '.$slot->format('d/m/Y H:i').'.';
            }
        }

        return 'No se encontró espacio en los próximos siete días.';
    }

    public function pay(int $id, string $method, mixed $amount, string $key): void
    {
        Gate::authorize('pos.sell');
        Validator::make(compact('method', 'amount', 'key'), ['method' => 'required|in:efectivo,transferencia,tarjeta', 'amount' => 'required|numeric|decimal:0,2|min:0.01|max:999999', 'key' => 'required|uuid'])->validate();
        DB::transaction(function () use ($id, $method, $amount, $key) {
            $shift = $this->shift();
            $order = Order::lockForUpdate()->findOrFail($id);
            if ($order->payments()->where('request_key', $key)->exists()) {
                return;
            }
            $cents = self::cents($amount);
            if ($order->status === 'cancelado' || $cents > self::cents($order->balance_due)) {
                $this->fail('El importe supera el saldo o el pedido está cancelado.');
            }
            $order->payments()->create(['request_key' => $key, 'cash_shift_id' => $shift->id, 'payment_method' => $method, 'amount' => self::money($cents), 'is_advance' => $cents < self::cents($order->balance_due)]);
            $order->update(['amount_paid' => self::money(self::cents($order->amount_paid) + $cents), 'balance_due' => self::money(self::cents($order->balance_due) - $cents)]);
            $this->audit($order, 'payment', ['amount' => $amount, 'method' => $method]);
            app(TicketService::class)->queue($order, 'pago');
        }, 3);
    }

    public function cancel(int $id, string $reason, ?int $itemId = null): void
    {
        Gate::authorize('orders.cancel');
        Validator::make(compact('reason'), ['reason' => 'required|string|min:5|max:255'])->validate();
        DB::transaction(function () use ($id, $reason, $itemId) {
            $shift = $this->shift();
            $order = Order::lockForUpdate()->findOrFail($id);
            if (in_array($order->status, ['cancelado', 'entregado'])) {
                $this->fail('Este pedido ya no admite cancelación.');
            }
            $items = $order->items()->where('is_cancelled', false)->when($itemId, fn ($q) => $q->whereKey($itemId))->get();
            if ($items->isEmpty()) {
                $this->fail('No hay partidas para cancelar.');
            }
            $removed = $items->sum(fn ($i) => self::cents($i->unit_price) * $i->quantity);
            $order->items()->whereIn('id', $items->pluck('id'))->update(['is_cancelled' => true]);
            $all = $order->items()->where('is_cancelled', false)->doesntExist();
            $total = $all ? 0 : self::cents($order->total) - $removed;
            $refund = max(0, self::cents($order->amount_paid) - $total);
            // Reembolso por los mismos métodos originales, incluido en el corte de la caja actual.
            $remaining = $refund;
            foreach ($order->payments()->selectRaw('payment_method, SUM(amount) as amount')->groupBy('payment_method')->get() as $payment) {
                $previous = self::cents(DB::table('order_refunds')->where('order_id', $id)->where('payment_method', $payment->payment_method)->sum('amount'));
                $part = min($remaining, self::cents($payment->amount) - $previous);
                if ($part <= 0) {
                    continue;
                }
                DB::table('order_refunds')->insert(['order_id' => $id, 'cash_shift_id' => $shift->id, 'user_id' => auth()->id(), 'payment_method' => $payment->payment_method, 'amount' => self::money($part), 'reason' => $reason, 'created_at' => now(), 'updated_at' => now()]);
                $remaining -= $part;
            }
            $net = self::cents($order->amount_paid) - $refund;
            $order->update(['total' => self::money($total), 'amount_paid' => self::money($net), 'balance_due' => self::money($total - $net), 'status' => $all ? 'cancelado' : $order->status, 'cancellation_reason' => $reason]);
            $this->audit($order, 'cancelled', ['reason' => $reason, 'items' => $items->pluck('id')->all(), 'refund' => self::money($refund)]);
            app(TicketService::class)->queue($order, 'cancelacion', $items->pluck('id')->all());
        }, 3);
    }

    public function status(int $id, string $next): void
    {
        Gate::authorize('dispatch.manage');
        DB::transaction(function () use ($id, $next) {
            $order = Order::lockForUpdate()->findOrFail($id);
            $allowed = ['pendiente' => ['en_preparacion'], 'en_preparacion' => ['listo'], 'listo' => $order->delivery_type === 'domicilio' ? ['en_ruta'] : ['entregado'], 'en_ruta' => ['entregado']];
            if (! in_array($next, $allowed[$order->status] ?? [])) {
                $this->fail('Transición de estado inválida.');
            }
            if ($next === 'en_ruta' && ! $order->delivery_driver_id) {
                $this->fail('Asigna un repartidor antes de salir a ruta.');
            }
            if ($next === 'entregado' && self::cents($order->balance_due) > 0) {
                $this->fail('Liquida el saldo antes de entregar.');
            }
            $order->update(['status' => $next]);
            $this->audit($order, 'status', ['next' => $next]);
        });
    }

    public function audit(Order $order, string $action, array $details): void
    {
        DB::table('audit_logs')->insert(['user_id' => auth()->id(), 'order_id' => $order->id, 'action' => $action, 'details' => json_encode($details), 'created_at' => now(), 'updated_at' => now()]);
    }
}
