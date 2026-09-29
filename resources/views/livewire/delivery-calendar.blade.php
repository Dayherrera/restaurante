<div>
<div class="page-heading"><div><span class="eyebrow">PLANIFICACIÓN</span><h1>Calendario de entregas</h1><p class="muted">Agenda por fecha de entrega. Consultar un pedido no lo libera a cocina.</p></div><a class="secondary" href="{{ route('orders') }}">Ver lista de pedidos</a></div>
<div class="filter-bar"><label class="check-label"><input type="checkbox" wire:model.live="showCancelled"> Mostrar cancelados</label><button class="secondary" wire:click="refreshCalendar">Actualizar</button><span class="muted">El conteo diario excluye cancelados.</span></div>
<div class="calendar-legend"><span class="calendar-state-pendiente">Pendiente / Programado</span><span class="calendar-state-en_preparacion">En preparación</span><span class="calendar-state-listo">Listo</span><span class="calendar-state-en_ruta">En ruta</span><span class="calendar-state-entregado">Entregado</span><span class="calendar-state-cancelado">Cancelado</span></div>
<div class="card delivery-calendar" wire:ignore><p data-calendar-error class="notice error" hidden role="alert"></p><p data-calendar-loading class="muted" role="status">Cargando agenda…</p><div data-calendar></div></div>
@if($orderId || $day)
<dialog wire:key="calendar-panel" wire:ignore.self class="calendar-dialog" x-data x-init="$el.showModal()" @cancel.prevent="$wire.closePanel()" aria-labelledby="calendar-panel-title">
<header class="row-between"><h2 id="calendar-panel-title">{{ $selectedOrder?'Detalle del pedido':'Entregas del '.\Carbon\Carbon::parse($day)->format('d/m/Y') }}</h2><button class="secondary" wire:click="closePanel" aria-label="Cerrar panel">Cerrar ×</button></header>
@if(session('calendar_notice'))<p class="notice success" role="status">{{ session('calendar_notice') }}</p>@endif
@if($errors->any())<div class="notice error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@if($selectedOrder)
@if($day)<button class="link-button" wire:click="backToDay">← Volver al día</button>@endif
<strong class="order-folio">Folio {{ $selectedOrder->order_number }}</strong><h3>{{ $selectedOrder->customer_name }}</h3><p>{{ $selectedOrder->customer_phone }}</p>
<p><span class="calendar-state-{{ $selectedOrder->status }}">{{ \App\Livewire\DeliveryCalendar::label($selectedOrder) }}</span></p>
<p><b>{{ $selectedOrder->scheduled_date->format('d/m/Y') }} · {{ substr($selectedOrder->scheduled_time,0,5) }}</b> · {{ $selectedOrder->delivery_type==='domicilio'?'A domicilio':'Recoger en sucursal' }}</p>
<p class="muted">{{ $selectedOrder->production_released_at?'Liberado a cocina':'Sin liberar a cocina' }}</p>
<h3>Productos y preparación</h3>
@foreach($selectedOrder->items as $item)<div class="order-line {{ $item->is_cancelled?'canceled':'' }}"><strong>{{ $item->quantity }} × {{ $item->product_name }}</strong>@if($item->is_cancelled)<span> · Partida cancelada</span>@endif
@foreach($item->selected_options??[] as $option)<p class="muted">{{ isset($option['group']) && strcasecmp(trim($option['group']),'Botanas a elegir')!==0?$option['group'].': ':'' }}{{ $option['quantity'] }} × {{ $option['name'] }}</p>@endforeach
@if($item->kitchen_notes)<p class="notice">{{ $item->kitchen_notes }}</p>@endif</div>@endforeach
@if($selectedOrder->delivery_type==='domicilio')<h3>Entrega</h3><p>{{ $selectedOrder->delivery_address }}</p><p>Repartidor: {{ $selectedOrder->driver?->name??'Sin asignar' }}</p>
@can('dispatch.manage')@if(!in_array($selectedOrder->status,['cancelado','entregado']))<form wire:submit="assignDriver" class="form-stack"><label>Asignar repartidor<select wire:model="driverId" required><option value="">Seleccionar</option>@foreach($drivers as $driver)<option value="{{ $driver->id }}">{{ $driver->name }}</option>@endforeach</select></label><button class="secondary" wire:loading.attr="disabled">Guardar repartidor</button></form>@endif @endcan @endif
<div class="card"><div class="row-between"><span>Total del pedido</span><strong>${{ number_format($selectedOrder->total,2) }}</strong></div><div class="row-between"><span>Pagado neto</span><strong>${{ number_format($selectedOrder->amount_paid,2) }}</strong></div><div class="row-between {{ $selectedOrder->balance_due>0?'balance-pending':'' }}"><span>Saldo pendiente</span><strong>${{ number_format($selectedOrder->balance_due,2) }}</strong></div></div>
<div class="action-row"><a class="primary" href="{{ route('orders.show',$selectedOrder) }}">Ver pedido / acciones</a>@can('orders.reprint')<button class="secondary" wire:click="reprint" wire:loading.attr="disabled">Reimprimir comprobante</button>@endcan</div><p class="muted tiny">Las autorizaciones de preparación y la edición de notas se realizan en Ver pedido. Cocina/barra solo reciben reimpresión si el pedido ya fue liberado.</p>
@else
<p>{{ $dayOrders->where('status','!=','cancelado')->count() }} pedidos vigentes</p>
@forelse($dayOrders as $order)<button class="calendar-day-order" wire:click="openOrder({{ $order->id }})"><strong>{{ substr($order->scheduled_time,0,5) }} · {{ $order->order_number }}</strong><span>{{ $order->customer_name }}</span><small>{{ \App\Livewire\DeliveryCalendar::label($order) }}{{ $order->balance_due>0?' · Saldo pendiente':'' }}</small></button>@empty<p class="empty">Sin pedidos para este día.</p>@endforelse
@endif
</dialog>
@endif
</div>
@script
<script>
window.createDeliveryCalendar($wire, $wire.$el, @js(now()->toDateString()));
</script>
@endscript
