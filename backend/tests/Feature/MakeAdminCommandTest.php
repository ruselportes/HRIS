<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * hris:make-admin — the first administrator on a fresh install (UC-01/FR-01).
 * A production database has no accounts, and the seeder that makes one uses
 * the password "password", so this is the only safe way in.
 */
class MakeAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    public function test_it_creates_an_admin_who_can_sign_in(): void
    {
        $this->role('admin');

        $this->artisan('hris:make-admin', ['--email' => 'admin@arcenasdev.ph'])
            ->expectsQuestion('First name', 'Rusel')
            ->expectsQuestion('Last name', 'Portes')
            ->expectsQuestion('Password (at least 12 characters, hidden as you type)', self::PASSWORD)
            ->expectsQuestion('Password again', self::PASSWORD)
            ->expectsOutputToContain('Administrator created.')
            ->expectsOutputToContain('ADC-0001')
            ->assertSuccessful();

        $admin = Employee::query()->where('employee_code', 'ADC-0001')->firstOrFail();
        $this->assertSame('admin', $admin->role->slug);
        $this->assertNotSame(self::PASSWORD, $admin->password, 'stored hashed, not as typed');

        foreach (['ADC-0001', 'admin@arcenasdev.ph'] as $identifier) {
            $this->postJson('/api/auth/login', ['identifier' => $identifier, 'password' => self::PASSWORD])
                ->assertOk()
                ->assertJsonPath('user.role.slug', 'admin');
        }

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->employee_id,
            'action_type' => AuditLog::ADMIN_CREATED_FROM_CONSOLE,
            'description' => 'Administrator ADC-0001 created from the server console.',
        ]);
    }

    public function test_it_refuses_before_the_roles_are_loaded(): void
    {
        $this->artisan('hris:make-admin')
            ->expectsOutputToContain('db:seed --class=RoleSeeder')
            ->assertFailed();

        $this->assertSame(0, Employee::count());
    }

    public function test_a_second_admin_needs_force_and_says_so_in_the_audit_log(): void
    {
        $first = $this->loginUser('admin', ['employee_code' => 'ADC-0001']);

        $this->artisan('hris:make-admin', ['--first-name' => 'Second', '--last-name' => 'Admin'])
            ->expectsOutputToContain('An administrator already exists (ADC-0001)')
            ->assertFailed();

        $this->assertSame(1, Employee::count());

        $this->artisan('hris:make-admin', ['--first-name' => 'Second', '--last-name' => 'Admin', '--force' => true])
            ->expectsQuestion('Password (at least 12 characters, hidden as you type)', self::PASSWORD)
            ->expectsQuestion('Password again', self::PASSWORD)
            ->assertSuccessful();

        $this->assertDatabaseHas('audit_logs', [
            'action_type' => AuditLog::ADMIN_CREATED_FROM_CONSOLE,
            'description' => "Administrator ADC-0002 created from the server console with --force, while {$first->employee_code} existed.",
        ]);
    }

    public function test_a_weak_or_mistyped_password_creates_nothing(): void
    {
        $this->role('admin');

        $cases = [
            'too short' => ['short-pass', 'short-pass', 'at least 12 characters'],
            'mismatch' => [self::PASSWORD, self::PASSWORD.'x', 'confirmation does not match'],
            'nothing typed' => ['', '', 'interactive terminal'],
        ];

        foreach ($cases as $case => [$password, $again, $message]) {
            $this->artisan('hris:make-admin', ['--first-name' => 'Rusel', '--last-name' => 'Portes'])
                ->expectsQuestion('Password (at least 12 characters, hidden as you type)', $password)
                ->expectsQuestion('Password again', $again)
                ->expectsOutputToContain($message)
                ->expectsOutputToContain('Nothing was created.')
                ->assertFailed();

            $this->assertSame(0, Employee::count(), $case);
            $this->assertSame(0, AuditLog::count(), $case);
        }
    }

    public function test_the_password_cannot_be_the_employee_code_whatever_its_case(): void
    {
        $this->role('admin');
        // A code long enough to pass the length rule on its own.
        Employee::factory()->create([
            'role_id' => $this->role('hr')->role_id,
            'employee_code' => 'ADC-99999998',
        ]);

        $this->artisan('hris:make-admin', ['--first-name' => 'Rusel', '--last-name' => 'Portes'])
            ->expectsQuestion('Password (at least 12 characters, hidden as you type)', 'adc-99999999')
            ->expectsQuestion('Password again', 'adc-99999999')
            ->expectsOutputToContain('cannot be the employee code')
            ->assertFailed();

        $this->assertSame(0, Employee::query()->whereHas('role', fn ($q) => $q->where('slug', 'admin'))->count());
    }

    public function test_an_email_already_in_use_is_refused(): void
    {
        $this->loginUser('hr', ['email' => 'taken@arcenasdev.ph']);
        $this->role('admin');

        $this->artisan('hris:make-admin', ['--first-name' => 'Rusel', '--last-name' => 'Portes', '--email' => 'taken@arcenasdev.ph'])
            ->expectsQuestion('Password (at least 12 characters, hidden as you type)', self::PASSWORD)
            ->expectsQuestion('Password again', self::PASSWORD)
            ->expectsOutputToContain('email has already been taken')
            ->assertFailed();

        $this->assertSame(1, Employee::count());
    }
}
