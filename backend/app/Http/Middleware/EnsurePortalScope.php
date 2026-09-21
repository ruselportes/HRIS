<?php

namespace App\Http\Middleware;

use App\Models\Employee;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deny-by-default gate for the worker self-service portal (Add-on B, FR-11).
 *
 * One middleware on the authenticated group, rather than a guard on every
 * route group: a portal role (worker/operator) may reach only the routes
 * named below, and everything else is 403. The allowlist is matched by ROUTE
 * NAME, not by path — a path prefix has no reliable segment boundary, so only
 * an exact, unique route name can identify an endpoint. Route names are
 * therefore security-relevant: renaming a route this list depends on breaks
 * the route-sweep test in PortalScopeTest.
 *
 * The same middleware refuses a separated employee of ANY role on every
 * request except auth/logout, so a live token cannot outlive the separation
 * (refusing sign-in alone would only stop future sessions).
 */
class EnsurePortalScope
{
    /**
     * Portal roles may reach only these named routes. W2 adds the
     * me.attendance and me.payslips names; they then fall into the sweep
     * test's not-403 branch automatically.
     */
    public const ALLOWED_PORTAL_ROUTES = [
        'auth.me',
        'auth.logout',
        'me.attendance.*',
        'me.payslips.*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Employee|null $employee */
        $employee = $request->user();

        if ($employee === null) {
            abort(403, 'You do not have permission to perform this action.');
        }

        if ($employee->employment_status === 'separated') {
            if (! $request->routeIs('auth.logout')) {
                abort(403, 'Your employment with the company has ended. Contact HR to restore access.');
            }

            return $next($request);
        }

        if ($employee->role?->isPortalRole()
            && ! $request->routeIs(...self::ALLOWED_PORTAL_ROUTES)) {
            abort(403, 'You do not have permission to perform this action.');
        }

        return $next($request);
    }
}
