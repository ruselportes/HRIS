<?php

namespace Tests;

use App\Models\Employee;
use App\Models\Role;
use App\Models\Site;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
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
