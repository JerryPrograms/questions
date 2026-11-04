<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('admin', fn (User $user): bool => in_array($user->role, ['super_admin', 'admin'], true));
        Gate::define('super-admin', fn (User $user): bool => $user->role === 'super_admin');
        Gate::define('manager', fn (User $user): bool => $user->role === 'manager');
    }
}
