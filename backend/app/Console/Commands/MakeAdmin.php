<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Role;
use App\Services\EmployeeService;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * The first administrator on a fresh install (UC-01/FR-01).
 *
 * Every account is made by someone already signed in: HR and admins provision
 * logins from the employee form. A production database starts with none, and
 * the seeders that make one (EmployeeSeeder) set the password to "password",
 * so they must never run there. This is the way in: run once on the server,
 * sign in, and make everyone else from the app.
 *
 * The password is only ever prompted for, hidden, never taken as an option,
 * so it cannot end up in the shell history or the process list.
 */
class MakeAdmin extends Command
{
    protected $signature = 'hris:make-admin
        {--first-name= : Given name (asked for when left out)}
        {--last-name= : Family name (asked for when left out)}
        {--email= : Optional; the admin can then sign in with it as well as the employee code}
        {--force : Create one even though an administrator exists (a lost admin password)}';

    protected $description = 'Create an administrator account from the server console (first install, or recovery)';

    /**
     * Longer than the app's 8: this account can make every other one, and the
     * server it is made on may be reachable from the internet (the tunnel).
     */
    private const MIN_PASSWORD = 12;

    public function handle(EmployeeService $employees): int
    {
        $role = Role::query()->where('slug', 'admin')->first();

        if ($role === null) {
            $this->error('There is no admin role yet. Load the roles first:');
            $this->line('  php artisan db:seed --class=RoleSeeder --force');

            return self::FAILURE;
        }

        $existing = Employee::query()->where('role_id', $role->role_id)->orderBy('employee_id')->first();

        if ($existing !== null && ! $this->option('force')) {
            $this->error("An administrator already exists ({$existing->employee_code}).");
            $this->line('Make further accounts in the app: Employees, then Add employee.');
            $this->line('Only if every admin password is lost, run this again with --force.');

            return self::FAILURE;
        }

        $code = $employees->nextEmployeeCode();

        $input = [
            'first_name' => $this->option('first-name') ?? $this->ask('First name'),
            'last_name' => $this->option('last-name') ?? $this->ask('Last name'),
            'email' => $this->option('email'),
            'password' => $this->secret('Password (at least '.self::MIN_PASSWORD.' characters, hidden as you type)'),
            'password_confirmation' => $this->secret('Password again'),
        ];

        $validator = Validator::make($input, [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', 'unique:employees,email'],
            'password' => [
                'required', 'string', 'min:'.self::MIN_PASSWORD, 'confirmed',
                function (string $attribute, mixed $value, Closure $fail) use ($code) {
                    if (strtolower((string) $value) === strtolower($code)) {
                        $fail('The password cannot be the employee code.');
                    }
                },
            ],
        ], [
            'password.required' => 'No password was given. Run this in an interactive terminal (docker compose exec, not exec -T).',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            $this->line('Nothing was created.');

            return self::FAILURE;
        }

        $data = $validator->validated();

        $admin = DB::transaction(function () use ($data, $role, $code, $existing) {
            $admin = Employee::create([
                'role_id' => $role->role_id,
                'employee_code' => $code,
                'email' => $data['email'] ?? null,
                'password' => $data['password'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'employment_status' => 'regular',
            ]);

            // The admin is its own actor: nobody signed in made it, and
            // actor_id cannot be null. --force is named, because it is the
            // one way to an admin that no existing admin agreed to.
            AuditLog::create([
                'actor_id' => $admin->employee_id,
                'action_type' => AuditLog::ADMIN_CREATED_FROM_CONSOLE,
                'description' => "Administrator {$code} created from the server console"
                    .($existing !== null ? ' with --force, while '.$existing->employee_code.' existed' : '')
                    .'.',
                'timestamp' => now(),
            ]);

            return $admin;
        });

        $this->info('Administrator created.');
        $this->line("  Employee code: {$admin->employee_code}   (sign in with this".($admin->email ? " or {$admin->email}" : '').')');
        $this->line("  Name:          {$admin->first_name} {$admin->last_name}");
        $this->line('Sign in at '.rtrim((string) config('app.url'), '/').'/login and make the other accounts from Employees.');

        return self::SUCCESS;
    }
}
