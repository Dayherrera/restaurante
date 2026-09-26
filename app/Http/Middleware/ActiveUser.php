<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ActiveUser
{
    public function handle(Request $r, Closure $next)
    {
        if (! $r->user()?->is_active) {
            auth()->logout();
            $r->session()->invalidate();
            $r->session()->regenerateToken();

            return redirect('/login');
        }

        return $next($r);
    }
}
