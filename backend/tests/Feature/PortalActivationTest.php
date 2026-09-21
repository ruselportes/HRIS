<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AuthController;
use App\Models\AuditLog;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Add-on B (FR-11) — the POST /auth/activate endpoint and its full failure
 * matrix. The route is registered in api.php since W3 (with the /portal
 * sign-in surface), so setUp's Route::has() guard never triggers and the
 * tests hit the production definition directly.
 *
 * The route-sweep in PortalScopeTest only visits auth:sanctum routes, so this
 * public route never crosses that sweep.
 */
class PortalActivationTest extends TestCase
{
    use RefreshDatabase;

    private const GENERIC_FAILURE = "Can't activate — contact HR.";

    protected function setUp(): void
    {
        parent::setUp();

        // The test route is self-guarding: the real auth.activate has been
        // registered in api.php since W3, so Route::has('auth.activate') is
        // true here and this fallback registration is skipped entirely — the
        // tests exercise the production route definition.
        if (! Route::has('auth.activate')) {
            Route::post('api/auth/activate', [AuthController::class, 'activate'])
                ->middleware('api')
                ->name('auth.activate');
        }
    }

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

    private function assertGenericFailure(mixed $response): void
    {
        $response->assertUnprocessable()
            ->assertJsonPath('message', self::GENERIC_FAILURE)
            ->assertJsonPath('errors.employee_code.0', self::GENERIC_FAILURE);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'employee_code' => 'ADC-0742',
            'date_of_birth' => '1990-05-12',
            'password' => 'secret99w',
        ], $overrides);
    }

    public function test_activation_succeeds_for_matching_code_and_date_of_birth(): void
    {
        $worker = $this->worker(['password' => null]);

        $this->activate($this->validPayload())->assertCreated();

        $worker->refresh();
        $this->assertTrue(Hash::check('secret99w', $worker->getAuthPassword()));
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $worker->employee_id,
            'action_type' => AuditLog::PORTAL_ACTIVATED,
        ]);

        // The per-code counter is cleared on success; the per-IP counter
        // decays on its own.
        $this->assertFalse(RateLimiter::tooManyAttempts('activate:code:'.sha1('adc-0742'), 5));
    }

    public function test_identity_and_shape_failures_keep_the_single_generic_message(): void
    {
        $this->worker(['password' => null]);

        $cases = [
            ['employee_code' => 'ADC-9999', 'date_of_birth' => '1990-05-12', 'password' => 'secret99w'],   // unknown code
            ['employee_code' => 'ADC-0742', 'date_of_birth' => '1980-01-01', 'password' => 'secret99w'],   // wrong DOB
            ['employee_code' => 'ADC-0742', 'date_of_birth' => '1990-05-12T16:00:00.000Z', 'password' => 'secret99w'], // ISO datetime, not Y-m-d
            ['employee_code' => 'ADC-0742', 'date_of_birth' => '1990-02-30', 'password' => 'secret99w'],   // impossible date
        ];

        foreach ($cases as $payload) {
            $this->assertGenericFailure($this->activate($payload));
        }

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_password_policy_failures_get_specific_messages_and_never_bump_the_code_counter(): void
    {
        $this->worker(['password' => null]);

        // Reviewed in W3: password-policy failures hinge on nothing but the
        // submitted values, so each answers with its own message (no code or
        // DOB oracle) and bumps only the per-IP backstop — a worker fumbling
        // their password must not burn the per-code attempts that stop
        // DOB-guessing.
        $cases = [
            ['password' => 'six', 'message' => 'Password must be at least 8 characters.'],
            ['password' => 'adc-0742', 'message' => 'Password cannot be the same as your employee ID.'],
            ['password' => 'xv19900512q', 'message' => 'Password cannot contain your date of birth.'],
            ['password' => 'juan05-12-1990', 'message' => 'Password cannot contain your date of birth.'],
            ['password' => 'juan1990.05.12', 'message' => 'Password cannot contain your date of birth.'],
            ['password' => 'juan05_12_1990', 'message' => 'Password cannot contain your date of birth.'],
            ['password' => 'juan05--12--1990', 'message' => 'Password cannot contain your date of birth.'],
        ];

        foreach ($cases as $case) {
            $this->postJson('/api/auth/activate', $this->validPayload(['password' => $case['password']]))
                ->assertUnprocessable()
                ->assertJsonPath('message', $case['message'])
                ->assertJsonPath('errors.password.0', $case['message']);
        }

        $this->assertDatabaseCount('audit_logs', 0);

        // All seven failures ran before any account lookup, so the per-code
        // counter that stops date-of-birth guessing was never touched.
        $this->assertFalse(RateLimiter::tooManyAttempts('activate:code:'.sha1('adc-0742'), 5));
    }

    public function test_invalid_utf8_password_is_refused_not_failed_over(): void
    {
        // A high byte (e.g. a form-encoded %FF) makes preg_replace('/u')
        // return null. The endpoint must refuse, never fall back to the
        // old non-UTF-8 split — that fallback would let a separator-written
        // birthday through as separate runs (review, 2026-09-21). W3 handles
        // this as a password-policy failure: its own message, per-IP bump
        // only, decided before any lookup. postJson would fail to encode the
        // byte, so this travels as a raw form post.
        $this->worker(['password' => null]);
        $this->withHeader('Accept', 'application/json')
            ->post('/api/auth/activate', [
                'employee_code' => 'ADC-0742',
                'date_of_birth' => '1990-05-12',
                'password' => "juan05_12_1990\xFF",
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Password contains characters we could not read.')
            ->assertJsonPath('errors.password.0', 'Password contains characters we could not read.');
    }

    public function test_password_with_separated_digit_groups_is_accepted(): void
    {
        // Each dash sits between a digit and a letter, so the separator-aware
        // run extraction must keep 1, 4, 0, 7 separate — nothing here spells
        // 19900512.
        $this->worker(['password' => null]);

        $this->activate([
            'employee_code' => 'ADC-0742',
            'date_of_birth' => '1990-05-12',
            'password' => 'Moon1-Kite4-Lion0-Star7',
        ])->assertCreated();
    }

    public function test_activation_refuses_an_already_activated_worker(): void
    {
        $worker = $this->worker(['password' => 'password']);

        $this->assertGenericFailure($this->activate($this->validPayload(['password' => 'newpass88x'])));

        $worker->refresh();
        $this->assertTrue(Hash::check('password', $worker->getAuthPassword()));
    }

    public function test_activation_refuses_a_staff_role_and_a_separated_worker(): void
    {
        $this->loginUser('hr', ['employee_code' => 'ADC-0700', 'password' => null, 'date_of_birth' => '1990-05-12']);
        $this->assertGenericFailure($this->activate($this->validPayload(['employee_code' => 'ADC-0700'])));

        $this->worker(['employment_status' => 'separated', 'password' => null]);
        $this->assertGenericFailure($this->activate($this->validPayload()));
    }

    public function test_activation_locks_per_employee_code_after_five_attempts(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertGenericFailure($this->activate($this->validPayload([
                'employee_code' => 'ADC-7777',
            ])));
        }

        // A throttled attempt is still the one generic message — no 429 oracle.
        $this->assertGenericFailure($this->activate($this->validPayload([
            'employee_code' => 'ADC-7777',
        ])));

        $this->assertTrue(RateLimiter::tooManyAttempts('activate:code:'.sha1('adc-7777'), 5));
    }

    public function test_activation_locks_per_ip_after_thirty_attempts(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->assertGenericFailure($this->activate($this->validPayload([
                'employee_code' => sprintf('ADC-%04d', 8000 + $i),
            ])));
        }

        $this->assertTrue(RateLimiter::tooManyAttempts('activate:ip:'.sha1('127.0.0.1'), 30));
    }

    public function test_activation_is_refused_after_hr_reset(): void
    {
        $worker = $this->worker(['password' => null]);
        $this->activate($this->validPayload())->assertCreated();

        $hr = $this->loginUser('hr');
        $this->actingAs($hr, 'sanctum')
            ->postJson('/api/employees/'.$worker->employee_id.'/reset-portal-access')
            ->assertOk();

        // The hijacker cannot simply re-activate — a password is now set.
        $this->assertGenericFailure($this->activate($this->validPayload(['password' => 'hijackS88'])));
    }
}
