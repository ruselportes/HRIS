<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePortalScope;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollDetail;
use App\Models\Role;
use App\Services\Payroll\PayPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Add-on B (FR-11, UC-11) — the worker portal lockdown, the HR reset, and the
 * portal's own-data reads (W2), in one suite. The HTTP activation matrix has
 * its own class (PortalActivationTest), which registers the auth.activate route
 * per test until W3 registers it for real; the route opens together with the
 * /portal sign-in surface. Its only survivor here is the password-seeded login
 * proof below, proving a portal role is refused sign-in until that day.
 */
class PortalScopeTest extends TestCase
{
    use RefreshDatabase;

    /** A worker on record, no password (nothing to sign in as yet). */
    private function worker(array $overrides = []): Employee
    {
        return $this->loginUser('worker', array_merge([
            'employee_code' => 'ADC-0742',
            'date_of_birth' => '1990-05-12',
        ], $overrides));
    }

    /* ------------------------------------------------------------------ *
     *  The deny-by-default sweep
     * ------------------------------------------------------------------ */

    public function test_every_authenticated_route_is_403_for_portal_roles_outside_the_allowlist(): void
    {
        foreach (Role::PORTAL_SLUGS as $slug) {
            $this->sweepPortalRole($slug);
        }
    }

    private function sweepPortalRole(string $slug): void
    {
        $user = $this->loginUser($slug, ['date_of_birth' => '1990-05-12']);
        $approvedRun = $this->seedApprovedPayslip($user);
        $swept = 0;

        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            // Group prefixes nest (e.g. api/auth), so match the URI root, not
            // getPrefix(). Web + health routes sit outside api/.
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            if (! in_array('auth:sanctum', $route->gatherMiddleware(), true)) {
                continue;
            }

            $method = strtoupper($route->methods()[0]);
            if ($method === 'HEAD') {
                $method = 'GET';
            }

            $uri = '/'.$this->sampleUri($route, $approvedRun);
            $swept++;

            // me/attendance demands its from/to pair; hand it a valid window
            // so the allowlisted route is actually exercised, not 422'd.
            $query = $route->getName() === 'me.attendance'
                ? ['from' => '2026-09-01', 'to' => '2026-09-30']
                : [];

            $response = $this->actingAs($user, 'sanctum')->call($method, $uri, $query);

            if ($this->isAllowedPortalRoute($route->getName())) {
                // The allowlisted routes all return 200 today; assertSuccessful
                // is the honest bound — a 500 or a 404 must fail the sweep too,
                // and me/payslips/{run} is exercised against the run seeded
                // above (sampleFor substitutes its payroll id for 'run').
                $response->assertSuccessful();
            } else {
                $response->assertForbidden();
            }
        }

        $this->assertGreaterThan(30, $swept, 'The sweep must actually visit routes for '.$slug.'.');
    }

    public function test_portal_403_beats_model_binding(): void
    {
        // A guessed employee id must read as an exact 403, never a 404 shadow.
        $this->actingAs($this->worker(), 'sanctum')
            ->getJson('/api/employees/999999')
            ->assertForbidden();
    }

    public function test_a_worker_cannot_reach_staff_endpoints(): void
    {
        $worker = $this->worker();

        foreach ([
            '/api/roles', '/api/sites', '/api/deployment', '/api/me/crew',
            '/api/me/devices', '/api/overrides', '/api/recovery/1/2026-09-01',
            '/api/payroll/runs', '/api/holidays', '/api/leaves', '/api/overtimes',
            '/api/reports/overview', '/api/reports/audit', '/api/employees',
            '/api/employees/next-code', '/api/attendance/sync/status',
        ] as $uri) {
            $this->actingAs($worker, 'sanctum')->getJson($uri)->assertForbidden();
        }

        foreach (['/api/attendance/sync', '/api/employees/1/reset-portal-access'] as $uri) {
            $this->actingAs($worker, 'sanctum')->postJson($uri)->assertForbidden();
        }
    }

    public function test_separated_employee_of_any_role_is_refused_everywhere_but_logout(): void
    {
        $separatedHr = $this->loginUser('hr', ['employment_status' => 'separated']);

        $this->actingAs($separatedHr, 'sanctum')->getJson('/api/roles')->assertForbidden();
        // Even auth/me is refused — a live token cannot outlive the separation.
        $this->actingAs($separatedHr, 'sanctum')->getJson('/api/auth/me')->assertForbidden();
        $this->actingAs($separatedHr, 'sanctum')->postJson('/api/auth/logout')->assertOk();
    }

    /* ------------------------------------------------------------------ *
     *  Login proof (the full activation matrix lives in PortalActivationTest)
     * ------------------------------------------------------------------ */

    public function test_activated_worker_cannot_login_until_w3(): void
    {
        // Portal sign-in opens in W3 along with auth.activate; until then even
        // a real password is refused at login.
        $this->worker(['password' => Hash::make('secret99w')]);

        $this->postJson('/api/auth/login', [
            'identifier' => 'ADC-0742',
            'password' => 'secret99w',
        ])->assertUnprocessable();
    }

    /* ------------------------------------------------------------------ *
     *  HR reset portal access
     * ------------------------------------------------------------------ */

    public function test_hr_reset_sets_temporary_password_and_revokes_live_tokens(): void
    {
        $worker = $this->worker();
        $token = $worker->createToken('hris-session')->plainTextToken;
        $hr = $this->loginUser('hr');

        $response = $this->actingAs($hr, 'sanctum')
            ->postJson('/api/employees/'.$worker->employee_id.'/reset-portal-access');

        $response->assertOk()
            ->assertJsonStructure(['temporary_password']);

        $temp = $response->json('temporary_password');
        $this->assertEquals(14, strlen($temp));
        $this->assertMatchesRegularExpression('/^[23456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz]+$/', $temp);

        $worker->refresh();
        $this->assertTrue(Hash::check($temp, $worker->getAuthPassword()));

        Auth::forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertUnauthorized();

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $hr->employee_id,
            'action_type' => AuditLog::PORTAL_ACCESS_RESET,
        ]);
    }

    public function test_reset_refuses_a_staff_target(): void
    {
        $hr = $this->loginUser('hr');
        $admin = $this->loginUser('admin');

        $this->actingAs($hr, 'sanctum')
            ->postJson('/api/employees/'.$admin->employee_id.'/reset-portal-access')
            ->assertUnprocessable();
    }

    public function test_reset_is_hr_or_admin_only(): void
    {
        $worker = $this->worker();
        $foreman = $this->loginUser('foreman');

        $this->actingAs($foreman, 'sanctum')
            ->postJson('/api/employees/'.$worker->employee_id.'/reset-portal-access')
            ->assertForbidden();
    }

    public function test_admin_can_reset_portal_access(): void
    {
        $worker = $this->worker();
        $admin = $this->loginUser('admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/employees/'.$worker->employee_id.'/reset-portal-access')
            ->assertOk()
            ->assertJsonStructure(['temporary_password']);
    }

    /* ------------------------------------------------------------------ *
     *  Helpers
     * ------------------------------------------------------------------ */

    private function sampleUri(Route $route, int $approvedRun): string
    {
        $uri = $route->uri();

        foreach ($route->parameterNames() as $parameter) {
            $sample = $this->sampleFor($route, $parameter, $approvedRun);
            $uri = str_replace(['{'.$parameter.'?}', '{'.$parameter.'}'], $sample, $uri);
        }

        return $uri;
    }

    private function sampleFor(Route $route, string $parameter, int $approvedRun): string
    {
        if ($parameter === 'run') {
            // me/payslips/{run} must be sampled against this role sweep's own
            // approved run — a fixed '1' would 404 for everyone but the row
            // that literally has id 1.
            return (string) $approvedRun;
        }

        return match ($route->wheres[$parameter] ?? null) {
            '\d{4}-\d{2}-[AB]' => '2026-09-A',
            '\d{4}-\d{2}-\d{2}' => '2026-09-01',
            default => '1',
        };
    }

    /** An approved payslip row for the sweep user; returns its payroll id. */
    private function seedApprovedPayslip(Employee $user): int
    {
        $period = PayPeriod::fromCode(now(config('attendance.timezone', 'Asia/Manila'))->format('Y-m').'-A');

        $payroll = Payroll::create([
            'employee_id' => $user->employee_id,
            'run_code' => $period->code,
            'pay_period_start' => $period->start,
            'pay_period_end' => $period->end,
            'gross_pay' => 8000.00,
            'net_pay' => 7200.00,
            'status' => Payroll::APPROVED,
        ]);

        PayrollDetail::create([
            'payroll_id' => $payroll->payroll_id,
            'regular_hours' => 80.00,
            'basic_pay' => 8000.00,
            'sss_employee' => 400.00,
            'philhealth_employee' => 200.00,
            'pagibig_employee' => 100.00,
            'withholding_tax' => 100.00,
            'deductions' => 800.00,
            'readiness' => PayrollDetail::READY,
            'blocked_reasons' => [],
            'breakdown' => [],
        ]);

        return $payroll->payroll_id;
    }

    private function isAllowedPortalRoute(?string $name): bool
    {
        if ($name === null) {
            return false;
        }

        foreach (EnsurePortalScope::ALLOWED_PORTAL_ROUTES as $pattern) {
            if (Str::is($pattern, $name)) {
                return true;
            }
        }

        return false;
    }
}
