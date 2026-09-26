<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PrintArea;
use App\Models\PrintJob;
use Illuminate\Support\Facades\DB;

class TicketService
{
    private const WIDTH = 32; // EC-PM-5890X, papel de 58 mm.

    private function text(string $text): string
    {
        $text = str_replace(['—', '–'], '-', $text);
        $text = \Illuminate\Support\Str::ascii($text, 'es');

        return preg_replace('/[\x00-\x1F\x7F]/', ' ', $text);
    }

    private function wrap(string $text): array
    {
        return explode("\n", wordwrap($this->text($text), self::WIDTH, "\n", true));
    }

    private function center(string $text): array
    {
        return array_map(fn ($line) => str_pad($line, strlen($line) + (int) floor((self::WIDTH - strlen($line)) / 2), ' ', STR_PAD_LEFT), $this->wrap($text));
    }

    private function columns(string $label, string $amount): array
    {
        $label = $this->text($label);
        $amount = $this->text($amount);
        if (strlen($label) + strlen($amount) + 1 > self::WIDTH) {
            return [...$this->wrap($label), str_pad($amount, self::WIDTH, ' ', STR_PAD_LEFT)];
        }

        return [$label.str_repeat(' ', self::WIDTH - strlen($label) - strlen($amount)).$amount];
    }

    private function money($value): string
    {
        return '$'.number_format((float) $value, 2, '.', ',');
    }

    public function queue(Order $order, string $kind, ?array $itemIds = null): void
    {
        $order->load('items', 'payments');
        $items = $order->items->when($itemIds !== null, fn ($q) => $q->whereIn('id', $itemIds));
        $areaIds = $items->pluck('print_area_id')->push(PrintArea::where('name', 'Caja')->value('id'))->filter()->unique();
        $rule = str_repeat('-', self::WIDTH);
        foreach ($areaIds as $areaId) {
            $area = PrintArea::findOrFail($areaId);
            $cash = $area->name === 'Caja';
            $lines = [...$this->center('CHAROLAS LOS MAGUEYES'), ...$this->center(strtoupper($kind).' / '.$area->name), $rule, ...$this->center('FOLIO: '.$order->order_number)];
            if ($cash) {
                $lines = [...$lines, ...$this->wrap('Emitido: '.now()->format('d/m/Y H:i'))];
            }
            $lines = [...$lines, ...$this->wrap('Cliente: '.$order->customer_name), ...$this->wrap('Tel: '.$order->customer_phone), ...$this->wrap('Entrega: '.$order->scheduled_date->format('d/m/Y').' '.substr($order->scheduled_time, 0, 5)), ...$this->wrap($order->delivery_type === 'domicilio' ? 'A DOMICILIO' : 'RECOGER EN SUCURSAL')];
            if ($order->delivery_type === 'domicilio') {
                $lines = [...$lines, ...$this->wrap($order->delivery_address ?? '')];
            }
            $lines[] = $rule;
            if ($cash) {
                $lines = [...$lines, ...$this->columns('CANT. / PRODUCTO', 'IMPORTE')];
            }
            foreach ($items as $item) {
                if (! $cash && $item->print_area_id != $areaId) {
                    continue;
                }
                $lines = [...$lines, ...$this->wrap(($item->is_cancelled ? 'ANULADO: ' : '').$item->quantity.' x '.$item->product_name)];
                if ($cash) {
                    $lines = [...$lines, ...$this->columns($item->quantity.' x '.$this->money($item->unit_price), $this->money($item->quantity * $item->unit_price))];
                }
                foreach ($item->selected_options ?? [] as $option) {
                    $lines = [...$lines, ...$this->wrap('  '.(isset($option['group']) && strcasecmp(trim($option['group']), 'Botanas a elegir') !== 0 ? $option['group'].': ' : '').$option['quantity'].' x '.$option['name'])];
                }
                if ($item->kitchen_notes) {
                    $lines = [...$lines, ...$this->wrap('NOTA: '.$item->kitchen_notes)];
                }
                $lines[] = '';
            }
            if ($cash) {
                $lines[] = $rule;
                if ($itemIds !== null) {
                    $lines = [...$lines, ...$this->wrap('MOVIMIENTO PARCIAL'), ...$this->wrap('Totales del pedido completo:')];
                }
                $lines = [...$lines, ...$this->columns('Productos vigentes', $this->money((float) $order->total - (float) $order->delivery_cost)), ...$this->columns('Envio', $this->money($order->delivery_cost)), ...$this->columns('TOTAL', $this->money($order->total)), $rule];
                foreach ($order->payments as $p) {
                    $lines = [...$lines, ...$this->columns(ucfirst($p->payment_method), $this->money($p->amount))];
                }
                foreach (DB::table('order_refunds')->where('order_id', $order->id)->get() as $r) {
                    $lines = [...$lines, ...$this->columns('Reembolso '.$r->payment_method, $this->money($r->amount))];
                }
                $lines = [...$lines, ...$this->columns('PAGADO NETO', $this->money($order->amount_paid)), ...$this->columns('SALDO PENDIENTE', $this->money($order->balance_due)), $rule, ...$this->center('Gracias por su preferencia'), ...$this->center('Conserve este comprobante')];
            }
            PrintJob::create(['order_id' => $order->id, 'print_area_id' => $areaId, 'kind' => $kind, 'payload' => implode("\n",$lines)]);
        }
    }
}
