<?php

namespace Tests;

use App\Models\Employee;
use App\Models\Role;
use App\Models\Site;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuse to run against anything but a test database, before
     * RefreshDatabase gets the chance to wipe it.
     *
     * Added after the first run inside Docker went to the dev MySQL: the
     * container's DB_CONNECTION=mysql outranked phpunit.xml. phpunit.xml now
     * forces the test settings, and this makes any future gap fail loudly
     * instead of silently emptying someone's database.
     */
    protected function setUpTraits()
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        // The one other allowed target is a database named *_testing, for
        // deliberately running the suite on MySQL — never the dev database.
        $isTestDatabase = ($connection === 'sqlite' && $database === ':memory:')
            || str_ends_with((string) $database, '_testing');

        if (! $isTestDatabase) {
            throw new \RuntimeException(
                "Tests must run on in-memory SQLite or a *_testing database, not [{$connection}: {$database}]. Refusing to touch it."
            );
        }

        return parent::setUpTraits();
    }

    /**
     * First-or-create by slug so RBAC tests get deterministic role records
     * without running the full seeder.
     */
    protected function role(string $slug): Role
    {
        return Role::firstOrCreate(
            ['slug' => $slug],
            ['role_name' => ucfirst($slug), 'description' => null],
        );
    }

    protected function site(string $name = 'Site 01 — Test'): Site
    {
        return Site::firstOrCreate(['site_name' => $name], ['location' => 'Cebu']);
    }

    /**
     * Build an employee record with a placed login password.
     */
    protected function loginUser(string $roleSlug, array $overrides = []): Employee
    {
        $site = $this->site();

        return Employee::factory()->create(array_merge([
            'role_id' => $this->role($roleSlug)->role_id,
            'site_id' => $site->site_id,
            'password' => 'password',
            'email' => 'u.'.uniqid().'@arcenasdev.ph',
        ], $overrides));
    }
}
