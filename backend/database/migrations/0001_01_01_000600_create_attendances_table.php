<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ERD: tbl_attendance (attendance_id PK, employee_id FK -> tbl_employee,
     *      crew_id FK -> tbl_crew, time_in, time_out, monotonic_timestamp,
     *      sync_status, override_flag)
     */
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->unsignedBigInteger('attendance_id')->autoIncrement()->primary();
            $table->foreignId('employee_id')->constrained('employees', 'employee_id');
            $table->foreignId('crew_id')->constrained('crews', 'crew_id');
            $table->timestamp('time_in')->nullable();
            $table->timestamp('time_out')->nullable();
            $table->unsignedBigInteger('monotonic_timestamp')->nullable();
            $table->string('sync_status')->default('pending');
            $table->string('override_flag')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};