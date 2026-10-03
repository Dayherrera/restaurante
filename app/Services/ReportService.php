<?php

namespace App\Services;

use App\Models\CashShift;
use App\Models\Order;
use App\Models\PrintArea;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ReportService
{
    public const TYPES = ['summary'=>'Resumen', 'production'=>'Producción', 'balances'=>'Saldos pendientes', 'payments'=>'Cobros y devoluciones'];
    public const STATES = ['pending'=>'Por preparar', 'all'=>'Todos los vigentes', 'pendiente'=>'Pendiente', 'en_preparacion'=>'En preparación', 'listo'=>'Listo', 'en_ruta'=>'En ruta', 'entregado'=>'Entregado'];

    public function build(array $f): array
    {
        $type = $f['report'];
        $from = $f['from'];
        $to = $f['to'];
        $start = $from ? Carbon::parse($from)->startOfDay() : null;
        $end = $to ? Carbon::parse($to)->addDay()->startOfDay() : null;
        $tables = [];
        $stats = [];
        $period = $from ? Carbon::parse($from)->format('d/m/Y').' al '.Carbon::parse($to)->format('d/m/Y') : 'Todas las fechas de entrega';
        $description = '';
        $table = function ($title, $headers, $rows, $money = [], $links = []) use (&$tables) {
            $tables[] = compact('title', 'headers', 'rows', 'money', 'links');
        };
        if ($type === 'production') {
            $description = 'Por fecha de entrega. Excluye pedidos y partidas canceladas. Las elecciones son cantidades seleccionadas, no kilos ni ingredientes.';
            $orders = Order::with('items')->whereDate('scheduled_date', '>=', $from)->whereDate('scheduled_date', '<=', $to)->where('status', '!=', 'cancelado')
                ->when($f['state'] === 'pending', fn ($q) => $q->whereIn('status', ['pendiente', 'en_preparacion']))
                ->when(!in_array($f['state'], ['pending', 'all']), fn ($q) => $q->where('status', $f['state']))
                ->orderBy('scheduled_date')->orderBy('scheduled_time')->orderBy('id')->get();
            $areas = PrintArea::pluck('name', 'id');
            $products = []; $options = []; $detail = []; $links = []; $orderIds = [];
            foreach ($orders as $o) {
                foreach ($o->items->where('is_cancelled', false) as $item) {
                    if ($f['area'] && $item->print_area_id != $f['area']) continue;
                    $orderIds[$o->id] = true;
                    $date = $o->scheduled_date->format('d/m/Y');
                    $hour = Carbon::parse($o->scheduled_time)->startOfHour()->format('g:i A');
                    $area = $areas[$item->print_area_id] ?? 'Sin área';
                    $key = $o->scheduled_date->toDateString().'|'.$hour.'|'.$item->product_id.'|'.$item->print_area_id;
                    $products[$key] ??= [$date, $hour, $area, $item->product_name, 0];
                    $products[$key][4] += $item->quantity;
                    $choices = [];
                    foreach ($item->selected_options ?? [] as $option) {
                        $qty = $item->quantity * (int) $option['quantity'];
                        $group = $option['group'] ?? 'Opciones';
                        $keyOption = json_encode([$date, $hour, $area, mb_strtoupper($group), mb_strtoupper($option['name'])]);
                        $options[$keyOption] ??= [$date, $hour, $area, $group, $option['name'], 0];
                        $options[$keyOption][5] += $qty;
                        $choices[] = $qty.' × '.$option['name'];
                    }
                    $links[count($detail)] = $o->id;
                    $detail[] = [$o->order_number, $date, Carbon::parse($o->scheduled_time)->format('g:i A'), $o->customer_name, self::STATES[$o->status], $o->production_released_at ? 'Liberado' : 'Sin liberar', $area, $item->product_name, (int)$item->quantity, implode('; ', $choices), $item->kitchen_notes ?? ''];
                }
            }
            $stats = ['Pedidos'=>count($orderIds), 'Unidades de productos'=>array_sum(array_column($products, 4))];
            $table('Productos por hora de entrega', ['Fecha','Hora','Área','Producto','Unidades'], array_values($products));
            $table('Elecciones de productos compuestos', ['Fecha','Hora','Área','Grupo','Elección','Cantidad'], array_values($options));
            $table('Detalle de producción', ['Folio','Fecha','Hora','Cliente','Estado','Liberación','Área','Producto','Unidades','Elecciones totales','Notas'], $detail, [], $links);
            $period .= ' · '.self::STATES[$f['state']].' · '.($areas[$f['area']] ?? 'Todas las áreas');
        } elseif ($type === 'balances') {
            $description = 'Saldo actual de todos los pedidos vigentes, incluso registrados en días anteriores. Las fechas opcionales filtran la entrega; no es un saldo histórico al cierre.';
            $orders = Order::where('status', '!=', 'cancelado')->where('balance_due', '>', 0)
                ->when($from, fn ($q) => $q->whereDate('scheduled_date', '>=', $from)->whereDate('scheduled_date', '<=', $to))
                ->when($f['bucket'] === 'overdue', fn ($q) => $q->whereDate('scheduled_date', '<', today()))
                ->when($f['bucket'] === 'today', fn ($q) => $q->whereDate('scheduled_date', today()))
                ->when($f['bucket'] === 'future', fn ($q) => $q->whereDate('scheduled_date', '>', today()))
                ->orderBy('scheduled_date')->orderBy('scheduled_time')->orderBy('id')->get();
            $rows = []; $links = []; $cents = 0;
            foreach ($orders as $o) {
                $bucket = $o->scheduled_date->toDateString() < today()->toDateString() ? 'Entrega atrasada' : ($o->scheduled_date->isToday() ? 'Entrega hoy' : 'Entrega futura');
                $links[count($rows)] = $o->id;
                $rows[] = [$o->order_number, $o->customer_name, $o->customer_phone, $o->scheduled_date->format('d/m/Y'), Carbon::parse($o->scheduled_time)->format('g:i A'), $bucket, self::STATES[$o->status], (float)$o->total, (float)$o->amount_paid, (float)$o->balance_due];
                $cents += OrderService::cents($o->balance_due);
            }
            $stats = ['Pedidos con saldo'=>$orders->count(), 'Saldo actual por cobrar'=>'$'.number_format($cents / 100, 2)];
            $table('Seguimiento de cobros', ['Folio','Cliente','Teléfono','Entrega','Hora','Clasificación','Estado','Total','Pagado neto','Saldo'], $rows, [7,8,9], $links);
            $period .= ' · '.(['all'=>'Todas las entregas','overdue'=>'Entregas atrasadas','today'=>'Entregas de hoy','future'=>'Entregas futuras'][$f['bucket']]);
        } else {
            $description = $type === 'summary' ? 'Importes actuales de pedidos por fecha de registro; cobros por fecha del movimiento; deuda global actual. Son indicadores distintos y no deben sumarse.' : 'Por fecha del movimiento de dinero, aunque el pedido se haya registrado o se entregue en otra fecha. Las devoluciones se presentan por separado.';
            $movements = collect();
            foreach (['order_payments'=>'Cobro', 'order_refunds'=>'Devolución'] as $source=>$label) {
                $rows = DB::table($source.' as m')->join('orders as o', 'o.id', '=', 'm.order_id')->join('cash_shifts as s', 's.id', '=', 'm.cash_shift_id')->join('users as u', 'u.id', '=', 's.user_id')
                    ->where('m.created_at', '>=', $start)->where('m.created_at', '<', $end)
                    ->when($f['method'], fn ($q) => $q->where('m.payment_method', $f['method']))
                    ->when($f['cashier'], fn ($q) => $q->where('s.user_id', $f['cashier']))
                    ->select('m.id', 'm.created_at', 'm.order_id', 'm.cash_shift_id', 'm.payment_method', 'm.amount', 'o.order_number', 'o.customer_name', 'u.name as cashier')->get();
                foreach ($rows as $row) { $row->kind = $label; $movements->push($row); }
            }
            $summary = []; $paidTotal = 0; $refundTotal = 0;
            foreach (['efectivo','transferencia','tarjeta'] as $method) {
                if ($f['method'] && $f['method'] !== $method) continue;
                $paid = $movements->where('payment_method',$method)->where('kind','Cobro')->sum(fn ($m) => OrderService::cents($m->amount));
                $refund = $movements->where('payment_method',$method)->where('kind','Devolución')->sum(fn ($m) => OrderService::cents($m->amount));
                $summary[] = [ucfirst($method), $paid / 100, $refund / 100, ($paid - $refund) / 100];
                $paidTotal += $paid; $refundTotal += $refund;
            }
            $stats = ['Cobrado'=>'$'.number_format($paidTotal / 100,2), 'Devuelto'=>'$'.number_format($refundTotal / 100,2), 'Cobro neto'=>'$'.number_format(($paidTotal-$refundTotal) / 100,2)];
            $table('Cobros por método', ['Método','Cobrado','Devuelto','Neto'], $summary, [1,2,3]);
            $detail = []; $links = [];
            foreach ($movements->sortBy('created_at') as $m) {
                $links[count($detail)] = $m->order_id;
                $folio = ctype_digit((string)$m->order_number) ? str_pad($m->order_number,7,'0',STR_PAD_LEFT) : $m->order_number;
                $detail[] = [$folio, Carbon::parse($m->created_at)->format('d/m/Y g:i A'), $m->kind, $m->customer_name, ucfirst($m->payment_method), $m->cashier, '#'.$m->cash_shift_id, (float)$m->amount, ($m->kind === 'Cobro' ? 1 : -1) * (float)$m->amount];
            }
            $table('Movimientos de dinero', ['Folio','Fecha y hora','Movimiento','Cliente','Método','Cajero del turno','Turno','Importe','Efecto neto'], $detail, [7,8], $links);
            if ($type === 'summary') {
                $orders = Order::where('created_at','>=',$start)->where('created_at','<',$end);
                $valid = (clone $orders)->where('status','!=','cancelado');
                $stats = ['Pedidos vigentes registrados'=>(clone $valid)->count(), 'Pedidos cancelados registrados'=>(clone $orders)->where('status','cancelado')->count(), 'Importe vigente con envío'=>'$'.number_format((clone $valid)->sum('total'),2)] + $stats + ['Saldo global actual'=>'$'.number_format(Order::where('status','!=','cancelado')->where('balance_due','>',0)->sum('balance_due'),2)];
                $table('Indicadores del resumen', ['Indicador','Valor'], collect($stats)->map(fn ($value,$key) => [$key,$value])->values()->all());
                $shifts = CashShift::where('closed_at','>=',$start)->where('closed_at','<',$end)->orderBy('closed_at')->get();
                $users = User::pluck('name','id'); $rows = [];
                foreach ($shifts as $shift) foreach ($shift->closing_summary ?? [] as $method=>$values) $rows[] = ['#'.$shift->id, $users[$shift->user_id] ?? '', $shift->closed_at->format('d/m/Y g:i A'), ucfirst($method), $values['expected'], $values['counted'], $values['difference']];
                $table('Arqueos cerrados en el período', ['Turno','Cajero','Cierre','Método','Esperado','Contado','Diferencia'], $rows, [4,5,6]);
            } else {
                $period .= ' · '.($f['method'] ? ucfirst($f['method']) : 'Todos los métodos').' · '.($f['cashier'] ? User::find($f['cashier'])?->name : 'Todos los cajeros');
            }
        }
        return ['title'=>self::TYPES[$type], 'description'=>$description, 'period'=>$period, 'stats'=>$stats, 'tables'=>$tables];
    }
}
