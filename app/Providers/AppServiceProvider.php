<?php

namespace App\Providers;

use App\Http\Middleware\ActiveUser;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Livewire::addPersistentMiddleware([ActiveUser::class]);
    }
}
