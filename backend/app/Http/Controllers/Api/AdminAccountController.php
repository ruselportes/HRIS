<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Users & Roles (C5, UC-01/FR-01) — the admin's account list. Login
 * provisioning already lives in the employee form; this page adds what only
 * an admin needs: every sign-in account, "sign out everywhere", and the
 * role-change history. Portal access resets stay on the HR employee record
 * (W3 owns them) and are not repeated here. Deliberately no lockout button:
 * the login lockout is per account *and* IP and expires on its own, so an
 * admin has no reliable way to clear it.
 *
 * Nothing sensitive leaves the server: codes and names only, never rates,
 * government IDs, or password hashes (only whether a password is set).
 */
class AdminAccountController extends Controller
{
    /**
     * GET /api/admin/accounts — everyone with a sign-in role, staff and
     * portal. "Sign-in role" is LOGIN_SLUGS plus PORTAL_SLUGS, so the list
     * stays correct if a record-only role is ever added.
     */
    public function index(): JsonResponse
    {
        $slugs = array_merge(Role::LOGIN_SLUGS, Role::PORTAL_SLUGS);

        $accounts = Employee::query()
            ->with(['role', 'site'])
            ->whereHas('role', fn ($q) => $q->whereIn('slug', $slugs))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->map(fn (Employee $e) => $this->account($e))
            ->values();

        $perRole = $accounts->groupBy('role.slug')->map->count();

        return response()->json([
            'data' => $accounts,
            'counts' => [
                'total' => $accounts->count(),
                'per_role' => $perRole->all(),
            ],
        ]);
    }

    /**
     * POST /api/admin/accounts/{employee}/sign-out — delete that person's
     * tokens. An admin signing themselves out keeps the current session, so
     * the click that did it still answers.
     */
    public function signOut(Request $request, Employee $employee): JsonResponse
    {
        $current = $request->user()->currentAccessToken();
        $keepSelf = (int) $request->user()->employee_id === (int) $employee->employee_id;

        $query = $employee->tokens();

        if ($keepSelf && $current?->id !== null) {
            $query->where('id', '!=', $current->id);
        }

        $deleted = $query->delete();

        AuditLog::create([
            'actor_id' => $request->user()->employee_id,
            'action_type' => AuditLog::SESSIONS_REVOKED,
            'description' => "Sessions revoked for {$employee->employee_code} by {$request->user()->employee_code}.",
            'timestamp' => now(),
        ]);

        return response()->json(['message' => 'Sessions revoked.', 'signed_out' => $deleted]);
    }

    /** @return array<string, mixed> */
    private function account(Employee $employee): array
    {
        $tokens = $employee->tokens()->get(['name', 'last_used_at']);

        $sessions = ['web' => 0, 'mobile' => 0, 'portal' => 0];

        foreach ($tokens as $token) {
            if (array_key_exists($token->name, $sessions)) {
                $sessions[$token->name]++;
            }
        }

        return [
            'employee_id' => $employee->employee_id,
            'employee_code' => $employee->employee_code,
            'full_name' => $employee->full_name,
            'role' => [
                'role_name' => $employee->role?->role_name,
                'slug' => $employee->role?->slug,
            ],
            'site' => $employee->site?->site_name,
            'employment_status' => $employee->employment_status,
            'has_password' => $employee->password !== null,
            'last_used_at' => $tokens->max('last_used_at'),
            'sessions' => $sessions,
        ];
    }
}
