<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_with_email_returns_token_and_user(): void
    {
        $hr = $this->loginUser('hr', ['email' => 'mreyes@arcenasdev.ph']);

        $response = $this->postJson('/api/auth/login', [
            'identifier' => 'mreyes@arcenasdev.ph',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['full_name', 'role' => ['slug']]])
            ->assertJsonPath('user.role.slug', 'hr');
    }

    public function test_login_with_employee_code_returns_token(): void
    {
        $hr = $this->loginUser('hr', ['employee_code' => 'ADC-2001']);

        $response = $this->postJson('/api/auth/login', [
            'identifier' => 'ADC-2001',
            'password' => 'password',
        ]);

        $response->assertOk()->assertJsonStructure(['token']);
    }

    public function test_wrong_password_returns_generic_validation_error(): void
    {
        $this->loginUser('hr', ['email' => 'mreyes@arcenasdev.ph']);

        $response = $this->postJson('/api/auth/login', [
            'identifier' => 'mreyes@arcenasdev.ph',
            'password' => 'wrong-password',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('message', 'Incorrect ID or password. 4 attempt(s) left before the account locks for 15 minutes.');
    }

    public function test_worker_without_password_cannot_login(): void
    {
        $this->loginUser('worker', ['password' => null, 'email' => 'worker@arcenasdev.ph']);

        $response = $this->postJson('/api/auth/login', [
            'identifier' => 'worker@arcenasdev.ph',
            'password' => 'password',
        ]);

        $response->assertUnprocessable();
    }

    public function test_separated_employee_of_any_role_cannot_login(): void
    {
        // Separation closes sign-in for staff too, so a live account cannot
        // come back. The same rule is enforced per-request by EnsurePortalScope;
        // worker/operator sign-in opened alongside the portal in W3.
        $hr = $this->loginUser('hr', ['employment_status' => 'separated', 'email' => 'gone@arcenasdev.ph']);
        $this->assertFalse($hr->canSignIn());

        $response = $this->postJson('/api/auth/login', [
            'identifier' => 'gone@arcenasdev.ph',
            'password' => 'password',
        ]);

        $response->assertUnprocessable();
    }

    public function test_login_locks_after_five_failed_attempts(): void
    {
        $this->loginUser('hr', ['email' => 'mreyes@arcenasdev.ph']);

        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/auth/login', [
                'identifier' => 'mreyes@arcenasdev.ph',
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $response = $this->postJson('/api/auth/login', [
            'identifier' => 'mreyes@arcenasdev.ph',
            'password' => 'password',
        ]);

        $response->assertStatus(429)
            ->assertJsonStructure(['retry_after'])
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'locked for 15 minutes'));

        RateLimiter::clear('login:'.sha1('mreyes@arcenasdev.ph|127.0.0.1'));
    }

    public function test_rate_limit_is_keyed_per_ip(): void
    {
        $this->loginUser('hr', ['email' => 'mreyes@arcenasdev.ph']);

        // Five failures from one IP lock that IP...
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/auth/login', ['identifier' => 'mreyes@arcenasdev.ph', 'password' => 'x'])
                ->assertUnprocessable();
        }

        // ...but a different IP is not throttled.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
            ->postJson('/api/auth/login', ['identifier' => 'mreyes@arcenasdev.ph', 'password' => 'password'])
            ->assertOk();

        RateLimiter::clear('login:'.sha1('mreyes@arcenasdev.ph|127.0.0.1'));
    }

    public function test_me_returns_authenticated_user(): void
    {
        $employee = $this->loginUser('hr');

        $res = $this->actingAs($employee, 'sanctum')->getJson('/api/auth/me');

        $res->assertOk()->assertJsonPath('user.employee_id', $employee->employee_id);
    }

    public function test_logout_revokes_token(): void
    {
        $employee = $this->loginUser('hr');

        $login = $this->postJson('/api/auth/login', [
            'identifier' => $employee->email,
            'password' => 'password',
        ])->assertOk();

        $token = $login->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/logout')
            ->assertOk();

        // Test artifact: AuthManager caches guard instances between requests in a
        // single test. Forget them so the next request re-verifies the token
        // against the DB (production requests never share a guard).
        Auth::forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_forgot_password_logs_audit_row_when_identifier_matches(): void
    {
        $employee = $this->loginUser('hr', ['employee_code' => 'ADC-3001']);

        $response = $this->postJson('/api/auth/forgot-password', ['employee_code' => 'ADC-3001']);

        $response->assertAccepted();

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $employee->employee_id,
            'action_type' => 'PASSWORD_RESET_REQUEST',
        ]);
    }

    public function test_forgot_password_for_unknown_identifier_writes_no_audit_row(): void
    {
        $response = $this->postJson('/api/auth/forgot-password', ['employee_code' => 'ADC-9999']);

        $response->assertAccepted()->assertJsonPath('message', 'If that Employee ID exists, HR has received your request.');

        $this->assertSame(0, AuditLog::count());
    }

    public function test_login_and_me_return_the_slim_session_record_without_rates_or_ids(): void
    {
        // The full employee row — rates and government IDs — must never sit
        // in browser storage, where the web app persists the login user.
        $sensitive = [
            'daily_rate', 'tin', 'sss', 'philhealth', 'pag_ibig',
            'date_of_birth', 'address', 'blood_type', 'email', 'mobile',
            'certification', 'emergency_contact',
        ];

        $hr = $this->loginUser('hr', ['email' => 'slim@arcenasdev.ph']);

        $login = $this->postJson('/api/auth/login', [
            'identifier' => 'slim@arcenasdev.ph',
            'password' => 'password',
        ])->assertOk();

        $login->assertJsonStructure(['token', 'user' => [
            'employee_id', 'employee_code', 'first_name', 'middle_name',
            'last_name', 'full_name', 'role' => ['role_name', 'slug'],
            'site' => ['site_id', 'site_name'],
        ]]);
        foreach ($sensitive as $field) {
            $login->assertJsonMissingPath("user.{$field}");
        }

        $me = $this->withHeader('Authorization', 'Bearer '.$login->json('token'))
            ->getJson('/api/auth/me')
            ->assertOk();
        foreach ($sensitive as $field) {
            $me->assertJsonMissingPath("user.{$field}");
        }
        $me->assertJsonPath('user.full_name', $hr->full_name)
            ->assertJsonPath('user.site.site_id', $hr->site_id);
    }

    public function test_hr_login_claiming_mobile_still_gets_an_expiring_web_token(): void
    {
        $hr = $this->loginUser('hr', ['email' => 'webmobile@arcenasdev.ph']);

        $this->postJson('/api/auth/login', [
            'identifier' => 'webmobile@arcenasdev.ph',
            'password' => 'password',
            'client' => 'mobile',
        ])->assertOk();

        // The claimed client only names the token when the role earns it —
        // staff claiming `mobile` still get the 12-hour web token.
        $token = $hr->tokens()->first();
        $this->assertSame('web', $token->name);
        $this->assertLessThan(
            60,
            abs(now()->addMinutes(720)->diffInSeconds($token->expires_at)),
        );
    }

    public function test_foreman_mobile_login_gets_a_thirty_day_token(): void
    {
        $foreman = $this->loginUser('foreman', ['email' => 'appforeman@arcenasdev.ph']);

        $this->postJson('/api/auth/login', [
            'identifier' => 'appforeman@arcenasdev.ph',
            'password' => 'password',
            'client' => 'mobile',
        ])->assertOk();

        $token = $foreman->tokens()->first();
        $this->assertSame('mobile', $token->name);
        $this->assertLessThan(
            120,
            abs(now()->addDays(30)->diffInSeconds($token->expires_at)),
        );
    }

    public function test_an_expired_token_is_refused(): void
    {
        $hr = $this->loginUser('hr');

        $plain = $hr->createToken('web', ['*'], now()->subHour())->plainTextToken;

        Auth::forgetGuards();

        $this->withHeader('Authorization', "Bearer {$plain}")
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }
}
