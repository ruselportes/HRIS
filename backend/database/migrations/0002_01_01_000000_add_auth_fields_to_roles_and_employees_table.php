<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 2: authentication support on the existing ERD tables.
     * - roles.slug                → machine key used by RBAC gate/middleware checks
     * - employees.employee_code   → ADC-NNNN identifier (prototype "Employee ID",
     *                                used alongside email as a login identifier)
     * - employees.email           → login identifier (nullable: most field workers
     *                                have no company email)
     * - employees.password        → login secret; null means the account cannot
     *                                sign in (Worker/Operator records never get one)
     * actor_id on audit_logs stays required by design: password-reset requests are
     * only written to the audit trail when the identifier matches an employee, so
     * the FK never has a null actor. See auth/forgot-password handling.
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('slug')->unique()->after('role_name');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->string('employee_code')->unique()->nullable()->after('role_id');
            $table->string('email')->unique()->nullable()->after('employee_code');
            $table->string('password')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['password', 'email', 'employee_code']);
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('slug');
        });
    }
};
