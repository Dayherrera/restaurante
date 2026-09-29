<?php
namespace App\Http\Middleware;
use App\Models\CashShift;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
class RequireOpenCashShift
{
    public function handle(Request $request, Closure $next)
    {
        Gate::authorize('pos.sell');
        if (!CashShift::where('user_id', $request->user()->id)->where('status', 'open')->exists()) {
            return redirect()->route('cash')->with('notice', 'Abre tu caja antes de comenzar un nuevo pedido.');
        }
        return $next($request);
    }
}
