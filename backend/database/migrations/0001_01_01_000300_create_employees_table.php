<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ERD: tbl_employee (employee_id PK, role_id FK -> tbl_role,
     *      first_name, last_name, trade_skill, daily_rate, certification,
     *      emergency_contact, employment_status).
     * Layered on top (per HRIS_ERD_reference.md, prototypes as source of
     * non-relational attribute detail): middle_name, date_of_birth, mobile,
     * civil_status, dependents, address, blood_type, tin, sss, philhealth,
     * pag_ibig, date_hired, cost_centre.
     */
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->unsignedBigInteger('employee_id')->autoIncrement()->primary();
            $table->foreignId('role_id')->constrained('roles', 'role_id');

            $table->string('first_name');
            $table->string('last_name');
            $table->string('middle_name')->nullable();
            $table->string('trade_skill')->nullable();
            $table->decimal('daily_rate', 10, 2)->nullable();
            $table->json('certification')->nullable();
            $table->json('emergency_contact')->nullable();
            $table->string('employment_status')->default('probationary');

            $table->date('date_of_birth')->nullable();
            $table->string('mobile')->nullable();
            $table->string('civil_status')->nullable();
            $table->unsignedTinyInteger('dependents')->nullable();
            $table->string('address')->nullable();
            $table->string('blood_type')->nullable();
            $table->string('tin')->nullable();
            $table->string('sss')->nullable();
            $table->string('philhealth')->nullable();
            $table->string('pag_ibig')->nullable();
            $table->date('date_hired')->nullable();
            $table->string('cost_centre')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};