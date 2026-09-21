<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Users & Roles (C5, UC-01/FR-01) — the admin's account list, "sign out
 * everywhere", and the role-change audit. Deliberately no lockout button:
 * the login lockout is per account and IP and expires on its own.
 */
class AdminAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admins_can_list_accounts(): void
    {
        $this->loginUser('worker');

        foreach (['hr', 'engineer', 'foreman', 'executive'] as $slug) {
            $user = $this->loginUser($slug);

            $this->actingAs($user, 'sanctum')
                ->getJson('/api/admin/accounts')
                ->assertForbidden();
        }

        $this->actingAs($this->loginUser('admin'), 'sanctum')
            ->getJson('/api/admin/accounts')
            ->assertOk();
    }

    public function test_accounts_carry_sessions_and_counts_but_no_sensitive_fields(): void
    {
        $admin = $this->loginUser('admin');
        $foreman = $this->loginUser('foreman');
        $worker = $this->loginUser('worker', ['password' => null]);
        $foreman->createToken('web');
        $foreman->createToken('mobile');

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/accounts')
            ->assertOk();

        $rows = collect($response->json('data'));
        $this->assertGreaterThanOrEqual(3, $rows->count());

        $row = $rows->firstWhere('employee_code', $foreman->employee_code);
        $this->assertSame($foreman->full_name, $row['full_name']);
        $this->assertSame('foreman', $row['role']['slug']);
        $this->assertTrue($row['has_password']);
        $this->assertSame(['web' => 1, 'mobile' => 1, 'portal' => 0], $row['sessions']);

        $noPassword = $rows->firstWhere('employee_code', $worker->employee_code);
        $this->assertFalse($noPassword['has_password']);

        $counts = $response->json('counts.per_role');
        $this->assertSame(1, $counts['admin']);
        $this->assertSame(1, $counts['foreman']);
        $this->assertSame(1, $counts['worker']);

        foreach (['daily_rate', 'tin', 'sss', 'philhealth', 'pag_ibig', 'date_of_birth', 'address', 'blood_type', 'password'] as $field) {
            $response->assertJsonMissingPath("data.0.{$field}");
        }
    }

    public function test_sign_out_deletes_tokens_but_keeps_the_admins_current_session(): void
    {
        $admin = $this->loginUser('admin');
        $foreman = $this->loginUser('foreman');
        $foreman->createToken('web');
        $foreman->createToken('mobile');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/accounts/{$foreman->employee_id}/sign-out")
            ->assertOk()
            ->assertJsonPath('signed_out', 2);

        $this->assertSame(0, $foreman->tokens()->count());
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->employee_id,
            'action_type' => AuditLog::SESSIONS_REVOKED,
        ]);
    }

    public function test_admin_signing_themselves_out_keeps_their_current_session(): void
    {
        $admin = $this->loginUser('admin');
        $token = $admin->createToken('web')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/admin/accounts/{$admin->employee_id}/sign-out")
            ->assertOk()
            ->assertJsonPath('signed_out', 0);

        Auth::forgetGuards();

        // Still signed in: the acting session survived its own revocation.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertOk();
    }

    public function test_role_change_is_audited_signs_out_and_appears_in_history(): void
    {
        $admin = $this->loginUser('admin');
        $foreman = $this->loginUser('foreman');
        $foreman->createToken('mobile');
        $workerRoleId = $this->role('worker')->role_id;

        $response = $this->actingAs($admin, 'sanctum')
            ->putJson("/api/employees/{$foreman->employee_id}", ['role_id' => $workerRoleId])
            ->assertOk();

        // The stale 30-day mobile token dies with the old role.
        $this->assertSame(0, $foreman->tokens()->count());

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->employee_id,
            'action_type' => AuditLog::ROLE_CHANGED,
        ]);

        $history = $response->json('role_change_history');
        $this->assertSame(1, count($history));
        $this->assertStringContainsString('foreman', $history[0]['description']);
        $this->assertStringContainsString('worker', $history[0]['description']);
        $this->assertStringContainsString($foreman->employee_code, $history[0]['description']);

        // An update that keeps the role writes no audit row.
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/employees/{$foreman->employee_id}", ['first_name' => 'Still'])
            ->assertOk()
            ->assertJsonCount(1, 'role_change_history');

        $this->assertSame(1, AuditLog::query()->where('action_type', AuditLog::ROLE_CHANGED)->count());
    }

    public function test_update_response_shape_is_unchanged_for_existing_clients(): void
    {
        $admin = $this->loginUser('admin');
        $subject = $this->loginUser('worker');

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/employees/{$subject->employee_id}", ['first_name' => 'Still'])
            ->assertOk()
            ->assertJsonPath('data.first_name', 'Still')
            ->assertJsonPath('role_change_history', []);
    }
}
