<?php

namespace App\Providers;

use App\Models\Employee;
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
        // Application-level RBAC gates, keyed off Employee.role.slug (ERD).
        // Mirror the EmployeePolicy role sets; the frontend / blade shells use
        // these to decide what UI affordances to render.
        Gate::define('manage-employees', fn (Employee $employee) => in_array($employee->role?->slug, ['hr', 'admin'], true));

        Gate::define('view-employees', fn (Employee $employee) => in_array($employee->role?->slug, ['hr', 'admin', 'engineer', 'executive'], true));
    }
}
