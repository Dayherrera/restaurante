<?php

namespace App\Services;

use App\Models\CashShift;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CashService
{
    public function open(string $initial): void
    {
        Gate::authorize('cash.manage');
        DB::transaction(function () use ($initial) {
            User::whereKey(auth()->id())->lockForUpdate()->firstOrFail();
            if (CashShift::where('user_id', auth()->id())->where('status', 'open')->exists()) {
                throw ValidationException::withMessages(['cash' => 'Ya tienes una caja abierta.']);
            }
            CashShift::create(['user_id' => auth()->id(), 'opened_at' => now(), 'initial_amount' => $initial]);
        });
    }

    public function totals(CashShift $shift): array
    {
        $summary = [];
        foreach (['efectivo', 'transferencia', 'tarjeta'] as $method) {
            $paid = OrderService::cents(DB::table('order_payments')->where('cash_shift_id', $shift->id)->where('payment_method', $method)->sum('amount'));
            $refunds = OrderService::cents(DB::table('order_refunds')->where('cash_shift_id', $shift->id)->where('payment_method', $method)->sum('amount'));
            $expected = $paid - $refunds;
            if ($method === 'efectivo') {
                $expected += OrderService::cents($shift->initial_amount);
                foreach (DB::table('cash_movements')->where('cash_shift_id', $shift->id)->get() as $m) {
                    $expected += ($m->type === 'entrada' ? 1 : -1) * OrderService::cents($m->amount);
                }
            }
            $summary[$method] = ['payments' => $paid / 100, 'refunds' => $refunds / 100, 'expected' => $expected / 100];
        }

        return $summary;
    }

    public function close(array $counts): CashShift
    {
        Gate::authorize('cash.manage');

        return DB::transaction(function () use ($counts) {
            $shift = app(OrderService::class)->shift();
            $summary = $this->totals($shift);
            foreach ($summary as $method => &$row) {
                $row['counted'] = (float) $counts[$method];
                $row['difference'] = (OrderService::cents($counts[$method]) - OrderService::cents($row['expected'])) / 100;
            }
            $shift->update(['status' => 'closed', 'closed_at' => now(), 'final_cash_real' => $counts['efectivo'], 'final_transfer_real' => $counts['transferencia'], 'final_card_real' => $counts['tarjeta'], 'closing_summary' => $summary]);

            return $shift;
        });
    }
}
