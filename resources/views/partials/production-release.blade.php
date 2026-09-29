@can('dispatch.manage')
@if($o->status==='pendiente' && !$o->production_released_at)
@if($o->scheduled_date->toDateString()>now()->toDateString())
<p class="notice">Entrega futura: {{ $o->scheduled_date->format('d/m/Y') }} a las {{ substr($o->scheduled_time,0,5) }}. No preparar sin autorización.</p>
@if(auth()->user()->hasRole('Administrador'))
<details class="card"><summary>Autorizar preparación anticipada</summary>
<form method="POST" action="{{ url('/pedidos/'.$o->id.'/liberar') }}" class="form-stack" x-data @submit="if(!confirm('¿Autorizar preparación anticipada? Entrega: {{ $o->scheduled_date->format('d/m/Y') }} a las {{ substr($o->scheduled_time,0,5) }}. Se imprimirá la comanda de cocina.')) $event.preventDefault()">
@csrf
<label>Motivo de preparación anticipada<textarea name="reason" required minlength="5" maxlength="500"></textarea></label><button class="danger">Confirmar inicio anticipado</button>
</form></details>
@else<p class="muted">Solo el administrador puede autorizar la preparación anticipada.</p>@endif
@else
<form method="POST" action="{{ url('/pedidos/'.$o->id.'/liberar') }}">
@csrf
<button class="primary">Liberar a cocina</button></form>
@endif
@elseif($o->production_released_at)<p class="muted">Liberado a producción: {{ $o->production_released_at->format('d/m/Y H:i') }}</p>@endif
@endcan
