<?php

namespace App\Providers;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Crew;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\Payroll;
use App\Models\PayrollDetail;
use App\Models\Site;
use App\Support\ResilientCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    /**
     * Which cached reads a write retires (Redis Cluster add-on). A model save
     * bumps every namespace whose cached answers could have changed, so the
     * next read builds a key the old entries cannot match.
     *
     * Reports read almost everything, so almost everything bumps them. The
     * cost is one counter increment per write, against recomputing aggregates
     * over the whole company on every dashboard load.
     */
    private const INVALIDATES = [
        Employee::class => ['employees', 'reports'],
        Attendance::class => ['attendance', 'reports'],
        AuditLog::class => ['attendance', 'reports'],
        Crew::class => ['attendance', 'reports'],
        Payroll::class => ['reports'],
        PayrollDetail::class => ['reports'],
        LeaveRequest::class => ['reports'],
        OvertimeRequest::class => ['reports'],
        Holiday::class => ['reference', 'reports'],
        Site::class => ['reference', 'employees', 'reports'],
    ];

    public function register(): void
    {
        $this->app->singleton(ResilientCache::class);
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

        Gate::define('manage-crews', fn (Employee $employee) => in_array($employee->role?->slug, ['engineer'], true));

        Gate::define('view-crews', fn (Employee $employee) => in_array($employee->role?->slug, ['hr', 'engineer', 'executive'], true));

        $this->invalidateCachedReadsOnWrite();
    }

    private function invalidateCachedReadsOnWrite(): void
    {
        $cache = $this->app->make(ResilientCache::class);

        foreach (self::INVALIDATES as $model => $namespaces) {
            $bump = function (Model $written) use ($cache, $namespaces): void {
                foreach ($namespaces as $namespace) {
                    $cache->bump($namespace);
                }
            };

            $model::saved($bump);
            $model::deleted($bump);
        }
    }
}
