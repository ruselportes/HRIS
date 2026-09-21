<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Add-on B (FR-11, W3) — the own-account password change, auth.password.
 *
 * No forced rotation at first sign-in (team decision, 2026-09-21): HR's
 * temporary password IS the login credential until the worker changes it
 * through this endpoint, and nothing here revokes the current token or any
 * other session.
 */
class PortalPasswordTest extends TestCase
{
    use RefreshDatabase;

    private function change(array $payload)
    {
        return $this->postJson('/api/auth/password', $payload);
    }

    public function test_a_worker_changes_their_own_password(): void
    {
        $worker = $this->loginUser('worker', ['employee_code' => 'ADC-0742']);

        $response = $this->actingAs($worker, 'sanctum')->change([
            'current_password' => 'password',
            'new_password' => 'FreshPass88x',
            'new_password_confirmation' => 'FreshPass88x',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Password updated.');

        $worker->refresh();
        $this->assertTrue(Hash::check('FreshPass88x', $worker->getAuthPassword()));

        // The session survives: nothing in this flow revokes tokens.
        $this->actingAs($worker, 'sanctum')->getJson('/api/auth/me')->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $worker->employee_id,
            'action_type' => AuditLog::PASSWORD_CHANGED,
        ]);
    }

    public function test_wrong_current_password_is_refused_then_throttled(): void
    {
        $worker = $this->loginUser('worker');

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($worker, 'sanctum')->change([
                'current_password' => 'wrong-password',
                'new_password' => 'FreshPass88x',
                'new_password_confirmation' => 'FreshPass88x',
            ])
                ->assertUnprocessable()
                ->assertJsonPath('errors.current_password.0', 'Your current password does not match.');
        }

        // Even a correct attempt is now locked out for the window.
        $this->actingAs($worker, 'sanctum')->change([
            'current_password' => 'password',
            'new_password' => 'FreshPass88x',
            'new_password_confirmation' => 'FreshPass88x',
        ])->assertStatus(429);

        $worker->refresh();
        $this->assertTrue(Hash::check('password', $worker->getAuthPassword()));
    }

    public function test_new_password_must_differ_from_the_current_one(): void
    {
        $worker = $this->loginUser('worker');

        $this->actingAs($worker, 'sanctum')->change([
            'current_password' => 'password',
            'new_password' => 'password',
            'new_password_confirmation' => 'password',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.new_password.0', 'Your new password must differ from the current one.');
    }

    public function test_new_password_cannot_be_the_employee_id(): void
    {
        $worker = $this->loginUser('worker', ['employee_code' => 'ADC-0742']);

        $this->actingAs($worker, 'sanctum')->change([
            'current_password' => 'password',
            'new_password' => 'adc-0742',
            'new_password_confirmation' => 'adc-0742',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.new_password.0', 'Password cannot be the same as your employee ID.');
    }

    public function test_new_password_cannot_contain_the_date_of_birth(): void
    {
        $worker = $this->loginUser('worker', ['date_of_birth' => '1990-05-12']);

        foreach (['xv19900512q', 'juan05-12-1990'] as $password) {
            $this->actingAs($worker, 'sanctum')->change([
                'current_password' => 'password',
                'new_password' => $password,
                'new_password_confirmation' => $password,
            ])
                ->assertUnprocessable()
                ->assertJsonPath('errors.new_password.0', 'Password cannot contain your date of birth.');
        }

        $worker->refresh();
        $this->assertTrue(Hash::check('password', $worker->getAuthPassword()));
    }

    public function test_new_password_must_be_eight_characters_and_confirmed(): void
    {
        $worker = $this->loginUser('worker');

        $this->actingAs($worker, 'sanctum')->change([
            'current_password' => 'password',
            'new_password' => 'six',
            'new_password_confirmation' => 'six',
        ])->assertUnprocessable()->assertJsonStructure(['errors' => ['new_password']]);

        $this->actingAs($worker, 'sanctum')->change([
            'current_password' => 'password',
            'new_password' => 'FreshPass88x',
            'new_password_confirmation' => 'Different88x',
        ])->assertUnprocessable()->assertJsonStructure(['errors' => ['new_password']]);

        $worker->refresh();
        $this->assertTrue(Hash::check('password', $worker->getAuthPassword()));
    }
}
