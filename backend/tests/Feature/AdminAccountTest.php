<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
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

    public function test_role_changes_are_listed_for_admins_only(): void
    {
        $admin = $this->loginUser('admin');
        $foreman = $this->loginUser('foreman');

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/employees/{$foreman->employee_id}", ['role_id' => $this->role('worker')->role_id])
            ->assertOk();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/role-changes')
            ->assertOk();

        $rows = $response->json('data');
        $this->assertSame(1, count($rows));
        $this->assertStringContainsString($foreman->employee_code, $rows[0]['description']);
        $this->assertSame($admin->employee_code, $rows[0]['actor_code']);

        $this->actingAs($this->loginUser('hr'), 'sanctum')
            ->getJson('/api/admin/role-changes')
            ->assertForbidden();
    }

    public function test_nobody_can_change_their_own_role(): void
    {
        foreach (['hr', 'admin'] as $slug) {
            $user = $this->loginUser($slug);

            $this->actingAs($user, 'sanctum')
                ->putJson("/api/employees/{$user->employee_id}", ['role_id' => $this->role('foreman')->role_id])
                ->assertUnprocessable();
        }
    }

    public function test_only_admins_can_grant_or_remove_protected_roles(): void
    {
        $hr = $this->loginUser('hr');
        $admin = $this->loginUser('admin');
        $foreman = $this->loginUser('foreman');
        $adminId = $this->role('admin')->role_id;
        $hrId = $this->role('hr')->role_id;
        $executiveId = $this->role('executive')->role_id;

        // HR cannot promote anyone to admin, HR or executive...
        foreach ([$adminId, $hrId, $executiveId] as $roleId) {
            $this->actingAs($hr, 'sanctum')
                ->putJson("/api/employees/{$foreman->employee_id}", ['role_id' => $roleId])
                ->assertUnprocessable();
        }

        // ...nor demote an admin away.
        $otherAdmin = $this->loginUser('admin');
        $this->actingAs($hr, 'sanctum')
            ->putJson("/api/employees/{$otherAdmin->employee_id}", ['role_id' => $this->role('foreman')->role_id])
            ->assertUnprocessable();

        // An admin can grant the admin role.
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/employees/{$foreman->employee_id}", ['role_id' => $adminId])
            ->assertOk();
        $this->assertSame($adminId, $foreman->fresh()->role_id);

        // HR keeps the field roles it already manages.
        $worker = $this->loginUser('foreman');
        $this->actingAs($hr, 'sanctum')
            ->putJson("/api/employees/{$worker->employee_id}", ['role_id' => $this->role('worker')->role_id])
            ->assertOk();
    }

    public function test_hr_cannot_set_an_admin_password_but_admins_can_set_anyones(): void
    {
        $hr = $this->loginUser('hr');
        $admin = $this->loginUser('admin');
        $subject = $this->loginUser('admin');

        $this->actingAs($hr, 'sanctum')
            ->putJson("/api/employees/{$subject->employee_id}", ['password' => 'TakenOver99x'])
            ->assertUnprocessable();
        $this->assertTrue(Hash::check('password', $subject->fresh()->getAuthPassword()));

        $worker = $this->loginUser('worker');
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/employees/{$worker->employee_id}", ['password' => 'ResetByAdmin99x'])
            ->assertOk();
        $this->assertTrue(Hash::check('ResetByAdmin99x', $worker->fresh()->getAuthPassword()));
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->employee_id,
            'action_type' => AuditLog::PASSWORD_CHANGED_BY_OTHER,
        ]);
    }

    public function test_hr_cannot_create_an_admin_account(): void
    {
        $hr = $this->loginUser('hr');
        $adminId = $this->role('admin')->role_id;

        $payload = [
            'employee_code' => 'ADC-4001',
            'email' => 'newadmin@arcenasdev.ph',
            'first_name' => 'New',
            'last_name' => 'Admin',
            'role_id' => $adminId,
            'site_id' => $this->site()->site_id,
            'date_of_birth' => '1995-01-01',
            'mobile' => '+63 917 555 0101',
            'date_hired' => '2026-09-01',
            'employment_status' => 'probationary',
            'daily_rate' => 820.00,
            'cost_centre' => 'CO-04',
        ];

        // With or without a password: HR cannot create the admin role.
        $this->actingAs($hr, 'sanctum')
            ->postJson('/api/employees', $payload + ['password' => 'FreshAdmin99x'])
            ->assertUnprocessable();
        $this->actingAs($hr, 'sanctum')
            ->postJson('/api/employees', $payload)
            ->assertUnprocessable();

        // An admin provisioning with a password is allowed — and audited.
        $admin = $this->loginUser('admin');
        $created = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/employees', $payload + ['password' => 'Provisioned99x'])
            ->assertCreated();
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->employee_id,
            'action_type' => AuditLog::PASSWORD_CHANGED_BY_OTHER,
        ]);
        $this->assertTrue(Hash::check(
            'Provisioned99x',
            Employee::find($created->json('data.employee_id'))->getAuthPassword()
        ));
    }
}
