<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Add-on B (FR-11, W3) — the own-account password change, auth.password.
 *
 * No forced rotation at first sign-in (team decision, 2026-09-21): HR's
 * temporary password IS the login credential until the worker changes it
 * through this endpoint. But the change signs every other session out — only
 * the session making the request survives.
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

        // The change itself travels on a real token. actingAs() would pin a
        // user onto the guard for the rest of the test with no token behind
        // it, so currentAccessToken() would resolve null on every later call
        // — exactly the false-green this test exists to prevent.
        $first = $worker->createToken('portal')->plainTextToken;

        $response = $this->withToken($first)->change([
            'current_password' => 'password',
            'new_password' => 'FreshPass88x',
            'new_password_confirmation' => 'FreshPass88x',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Password updated.');

        $worker->refresh();
        $this->assertTrue(Hash::check('FreshPass88x', $worker->getAuthPassword()));

        // Only the session making the request survives — and this has to be
        // proven with real tokens. actingAs() carries no token, so
        // currentAccessToken() would be null, the `where id !=` branch would
        // never run, and a "current one still works" check would pass while
        // proving nothing.
        $current = $worker->createToken('portal')->plainTextToken;
        $other = $worker->createToken('web')->plainTextToken;

        // The guard instance (and its authenticated user) persists across
        // requests inside one test: without forgetting it, the second change
        // would re-authenticate as the FIRST token's session, spare that one
        // instead, and kill $current — the exact false-red mirror of the
        // actingAs() false-green above. Production never sees this; every
        // real request boots a fresh guard.
        Auth::forgetGuards();

        $this->withToken($current)->change([
            'current_password' => 'FreshPass88x',
            'new_password' => 'SecondPass99x',
            'new_password_confirmation' => 'SecondPass99x',
        ])->assertOk();

        Auth::forgetGuards();

        $this->withToken($other)->getJson('/api/auth/me')->assertUnauthorized();

        Auth::forgetGuards();

        $this->withToken($current)->getJson('/api/auth/me')->assertOk();

        // The first token was another session by the second change, so it
        // died with it.
        Auth::forgetGuards();

        $this->withToken($first)->getJson('/api/auth/me')->assertUnauthorized();

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

        // Even a correct attempt is now locked out for the window — and from a
        // different address too, proving the throttle is per account, not per
        // account+IP: with the IP in the key a stolen token could hop
        // addresses and keep guessing.
        $this->actingAs($worker, 'sanctum')->change([
            'current_password' => 'password',
            'new_password' => 'FreshPass88x',
            'new_password_confirmation' => 'FreshPass88x',
        ])->assertStatus(429);

        $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])
            ->actingAs($worker, 'sanctum')
            ->postJson('/api/auth/password', [
                'current_password' => 'password',
                'new_password' => 'FreshPass88x',
                'new_password_confirmation' => 'FreshPass88x',
            ])
            ->assertStatus(429);

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
