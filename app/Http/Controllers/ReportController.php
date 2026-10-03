<?php

namespace App\Http\Controllers;

use App\Models\PrintArea;
use App\Models\User;
use App\Services\ReportService;
use App\Services\ReportWorkbook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    public function index(Request $request, ReportService $service)
    {
        Gate::authorize('reports.view');
        $data = $request->validate([
            'report'=>['nullable', Rule::in(array_keys(ReportService::TYPES))],
            'from'=>'nullable|required_with:to|date_format:Y-m-d',
            'to'=>'nullable|required_with:from|date_format:Y-m-d|after_or_equal:from',
            'state'=>['nullable', Rule::in(array_keys(ReportService::STATES))],
            'area'=>'nullable|integer|exists:print_areas,id',
            'bucket'=>'nullable|in:all,overdue,today,future',
            'method'=>'nullable|in:efectivo,transferencia,tarjeta',
            'cashier'=>'nullable|integer|exists:users,id',
            'export'=>'nullable|in:xlsx',
        ]);
        $f = [
            'report'=>$data['report'] ?? 'summary', 'from'=>$data['from'] ?? null, 'to'=>$data['to'] ?? null,
            'state'=>$data['state'] ?? 'pending', 'area'=>$data['area'] ?? '', 'bucket'=>$data['bucket'] ?? 'all',
            'method'=>$data['method'] ?? '', 'cashier'=>$data['cashier'] ?? '',
        ];
        if ($f['report'] !== 'balances') {
            $f['from'] ??= today()->toDateString();
            $f['to'] ??= $f['from'];
        }
        if ($f['report'] !== 'payments') { $f['method'] = ''; $f['cashier'] = ''; }
        $report = $service->build($f);
        if (($data['export'] ?? null) === 'xlsx') {
            $path = app(ReportWorkbook::class)->create($report);
            return response()->download($path, 'reporte-'.$f['report'].'-'.now()->format('Ymd-His').'.xlsx', ['Content-Type'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend(true);
        }
        return view('reports', compact('report','f') + ['types'=>ReportService::TYPES, 'states'=>ReportService::STATES, 'areas'=>PrintArea::orderBy('name')->get(), 'cashiers'=>User::orderBy('name')->get()]);
    }
}
