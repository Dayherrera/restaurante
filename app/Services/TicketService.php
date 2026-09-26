<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PrintArea;
use App\Models\PrintJob;
use Illuminate\Support\Facades\DB;

class TicketService
{
    public function queue(Order $order, string $kind, ?array $itemIds = null): void
    {
        $order->load('items', 'payments');
        $items = $order->items->when($itemIds !== null, fn ($q) => $q->whereIn('id', $itemIds));
        $areaIds = $items->pluck('print_area_id')->push(PrintArea::where('name', 'Caja')->value('id'))->filter()->unique();
        foreach ($areaIds as $areaId) {
            $area = PrintArea::findOrFail($areaId);
            $lines = ['CHAROLAS LOS MAGUEYES', strtoupper($kind).' / '.$area->name, $order->order_number, $order->customer_name.' / '.$order->customer_phone, 'Entrega: '.$order->scheduled_date->format('d/m/Y').' '.$order->scheduled_time];
            if ($order->delivery_type === 'domicilio') {
                $lines[] = 'Domicilio: '.$order->delivery_address;
            }
            foreach ($items as $item) {
                if ($area->name !== 'Caja' && $item->print_area_id != $areaId) {
                    continue;
                }
                $lines[] = ($item->is_cancelled ? 'ANULADO ' : '').$item->quantity.' x '.$item->product_name;
                if ($item->product_description) {
                    $lines[] = $item->product_description;
                }
                foreach ($item->selected_options ?? [] as $option) {
                    $lines[] = '  '.(isset($option['group']) ? $option['group'].': ' : '').$option['quantity'].' '.$option['name'];
                }
                if ($item->kitchen_notes) {
                    $lines[] = 'NOTA: '.$item->kitchen_notes;
                }
            }
            if ($area->name === 'Caja') {
                $lines[] = 'Total: $'.$order->total;
                foreach ($order->payments as $payment) {
                    $lines[] = $payment->payment_method.': $'.$payment->amount;
                }
                foreach (DB::table('order_refunds')->where('order_id', $order->id)->get() as $refund) {
                    $lines[] = 'Reembolso '.$refund->payment_method.': $'.$refund->amount;
                }
                $lines[] = 'Pagado neto: $'.$order->amount_paid;
                $lines[] = 'Saldo pendiente: $'.$order->balance_due;
            }
            PrintJob::create(['order_id' => $order->id, 'print_area_id' => $areaId, 'kind' => $kind, 'payload' => implode("\n", $lines)]);
        }
    }
}
