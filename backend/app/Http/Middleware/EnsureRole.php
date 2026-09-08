<?php

namespace App\Http\Middleware;

use App\Models\Employee;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level RBAC gate. Usage: role:hr,admin
 * Checks the authenticated employee's role slug from the ERD role column.
 * Policies remain the primary authorization check; this middleware is a
 * defense-in-depth layer for high-level route groups.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$slugs): Response
    {
        /** @var Employee|null $employee */
        $employee = $request->user();

        if ($employee === null || ! in_array($employee->role?->slug, $slugs, true)) {
            abort(403, 'You do not have permission to perform this action.');
        }

        return $next($request);
    }
}
