<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['role_name' => 'HR Personnel', 'slug' => 'hr', 'description' => 'Manages employee records, attendance, leave, and payroll.'],
            ['role_name' => 'Site Foreman', 'slug' => 'foreman', 'description' => 'Records crew attendance on site via the mobile app.'],
            ['role_name' => 'Site Engineer / Construction Manager', 'slug' => 'engineer', 'description' => 'Monitors attendance and manages crew deployment across assigned sites.'],
            ['role_name' => 'Worker', 'slug' => 'worker', 'description' => 'Field worker on a crew; record-only, no HRIS login.'],
            ['role_name' => 'Operator', 'slug' => 'operator', 'description' => 'Heavy equipment operator; record-only, no HRIS login.'],
            ['role_name' => 'System Administrator', 'slug' => 'admin', 'description' => 'Provisions accounts and roles, monitors devices and sync health.'],
            ['role_name' => 'Executive', 'slug' => 'executive', 'description' => 'Read-only access to company-wide reports and analytics.'],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(['slug' => $role['slug']], $role);
        }
    }
}