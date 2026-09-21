<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePortalScope;
use App\Models\AuditLog;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Add-on B (FR-11, UC-11) — worker portal lockdown, activation, and the HR
 * reset, in one suite because they ship in one commit (W1): sign-in for
 * portal roles stays closed until W3.
 */
class PortalScopeTest extends TestCase
{
    use RefreshDatabase;

    private const GENERIC_FAILURE = "Can't activate — contact HR.";

    /** A worker on record, no password (nothing to sign in as yet). */
    private function worker(array $overrides = []): Employee
    {
        return $this->loginUser('worker', array_merge([
            'employee_code' => 'ADC-0742',
            'date_of_birth' => '1990-05-12',
        ], $overrides));
    }

    private function activate(array $payload): mixed
    {
        return $this->postJson('/api/auth/activate', $payload);
    }

    private function assertGenericActivationFailure(mixed $response): void
    {
        $response->assertUnprocessable()
            ->assertJsonPath('message', self::GENERIC_FAILURE)
            ->assertJsonPath('errors.employee_code.0', self::GENERIC_FAILURE);
    }

    /* ------------------------------------------------------------------ *
     *  The deny-by-default sweep
     * ------------------------------------------------------------------ */

    public function test_every_authenticated_route_is_403_for_a_worker_outside_the_allowlist(): void
    {
        $worker = $this->worker();
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

            $uri = '/'.$this->sampleUri($route);
            $swept++;

            $response = $this->actingAs($worker, 'sanctum')->call($method, $uri);

            if ($this->isAllowedPortalRoute($route->getName())) {
                $this->assertNotSame(
                    403,
                    $response->getStatusCode(),
                    'Portal-allowlisted route '.$route->getName().' must not be denied.'
                );
            } else {
                $response->assertForbidden();
            }
        }

        $this->assertGreaterThan(30, $swept, 'The sweep must actually visit routes.');
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
     *  Activation
     * ------------------------------------------------------------------ */

    public function test_activation_succeeds_for_matching_code_and_date_of_birth(): void
    {
        $worker = $this->worker(['password' => null]);

        $this->activate([
            'employee_code' => 'ADC-0742',
            'date_of_birth' => '1990-05-12',
            'password' => 'secret99w',
        ])->assertCreated();

        $worker->refresh();
        $this->assertTrue(Hash::check('secret99w', $worker->getAuthPassword()));
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $worker->employee_id,
            'action_type' => AuditLog::PORTAL_ACTIVATED,
        ]);
    }

    public function test_activated_worker_cannot_login_until_w3(): void
    {
        $this->worker(['password' => null]);
        $this->activate([
            'employee_code' => 'ADC-0742',
            'date_of_birth' => '1990-05-12',
            'password' => 'secret99w',
        ])->assertCreated();

        $this->postJson('/api/auth/login', [
            'identifier' => 'ADC-0742',
            'password' => 'secret99w',
        ])->assertUnprocessable();
    }

    public function test_every_activation_failure_is_the_same_generic_message(): void
    {
        $this->worker(['password' => null]);

        $cases = [
            ['employee_code' => 'ADC-9999', 'date_of_birth' => '1990-01-01', 'password' => 'secret99w'],   // unknown code
            ['employee_code' => 'ADC-0742', 'date_of_birth' => '1980-01-01', 'password' => 'secret99w'],   // wrong DOB
            ['employee_code' => 'ADC-0742', 'date_of_birth' => 'not-a-date', 'password' => 'secret99w'],   // malformed DOB
            ['employee_code' => 'ADC-0742', 'date_of_birth' => '1990-05-12', 'password' => 'six'],         // too short
            ['employee_code' => 'ADC-0742', 'date_of_birth' => '1990-05-12', 'password' => 'adc-0742'],    // equals the code
            ['employee_code' => 'ADC-0742', 'date_of_birth' => '1990-05-12', 'password' => 'xv19900512q'], // digits spell the DOB
        ];

        foreach ($cases as $payload) {
            $this->assertGenericActivationFailure($this->activate($payload));
        }

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_activation_refuses_an_already_activated_worker(): void
    {
        $worker = $this->worker(['password' => 'password']);

        $this->assertGenericActivationFailure($this->activate([
            'employee_code' => 'ADC-0742',
            'date_of_birth' => '1990-05-12',
            'password' => 'newpass88x',
        ]));

        $worker->refresh();
        $this->assertTrue(Hash::check('password', $worker->getAuthPassword()));
    }

    public function test_activation_refuses_a_staff_role_and_a_separated_worker(): void
    {
        $this->loginUser('hr', ['employee_code' => 'ADC-0700', 'password' => null, 'date_of_birth' => '1990-05-12']);
        $this->assertGenericActivationFailure($this->activate([
            'employee_code' => 'ADC-0700',
            'date_of_birth' => '1990-05-12',
            'password' => 'secret99w',
        ]));

        $this->worker(['employment_status' => 'separated', 'password' => null]);
        $this->assertGenericActivationFailure($this->activate([
            'employee_code' => 'ADC-0742',
            'date_of_birth' => '1990-05-12',
            'password' => 'secret99w',
        ]));
    }

    public function test_activation_locks_per_employee_code_after_five_attempts(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertGenericActivationFailure($this->activate([
                'employee_code' => 'ADC-7777',
                'date_of_birth' => '1990-01-01',
                'password' => 'secret99w',
            ]));
        }

        // A throttled attempt is still the one generic message — no 429 oracle.
        $this->assertGenericActivationFailure($this->activate([
            'employee_code' => 'ADC-7777',
            'date_of_birth' => '1990-01-01',
            'password' => 'secret99w',
        ]));
    }

    public function test_activation_locks_per_ip_after_thirty_attempts(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->assertGenericActivationFailure($this->activate([
                'employee_code' => sprintf('ADC-%04d', 8000 + $i),
                'date_of_birth' => '1990-01-01',
                'password' => 'secret99w',
            ]));
        }

        $this->assertTrue(RateLimiter::tooManyAttempts('activate:ip:'.sha1('127.0.0.1'), 30));

        RateLimiter::clear('activate:ip:'.sha1('127.0.0.1'));
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

    public function test_activation_is_refused_after_hr_reset(): void
    {
        $worker = $this->worker(['password' => null]);
        $this->activate([
            'employee_code' => 'ADC-0742',
            'date_of_birth' => '1990-05-12',
            'password' => 'secret99w',
        ])->assertCreated();

        $hr = $this->loginUser('hr');
        $this->actingAs($hr, 'sanctum')
            ->postJson('/api/employees/'.$worker->employee_id.'/reset-portal-access')
            ->assertOk();

        // The hijacker cannot simply re-activate — a password is now set.
        $this->assertGenericActivationFailure($this->activate([
            'employee_code' => 'ADC-0742',
            'date_of_birth' => '1990-05-12',
            'password' => 'hijackS88',
        ]));
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

    private function sampleUri(Route $route): string
    {
        $uri = $route->uri();

        foreach ($route->parameterNames() as $parameter) {
            $sample = $this->sampleFor($route, $parameter);
            $uri = str_replace(['{'.$parameter.'?}', '{'.$parameter.'}'], $sample, $uri);
        }

        return $uri;
    }

    private function sampleFor(Route $route, string $parameter): string
    {
        return match ($route->wheres[$parameter] ?? null) {
            '\d{4}-\d{2}-[AB]' => '2026-09-A',
            '\d{4}-\d{2}-\d{2}' => '2026-09-01',
            default => '1',
        };
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
