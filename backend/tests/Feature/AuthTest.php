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
}
