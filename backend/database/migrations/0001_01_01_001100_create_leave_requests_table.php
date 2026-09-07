<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ERD: tbl_leave_request (leave_id PK, employee_id FK -> tbl_employee,
     *      approved_by FK -> tbl_employee [self-ref], leave_type, date_from,
     *      date_to, status)
     */
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('leave_id')->autoIncrement()->primary();
            $table->foreignId('employee_id')->constrained('employees', 'employee_id');
            $table->foreignId('approved_by')->nullable()->constrained('employees', 'employee_id');
            $table->string('leave_type')->nullable();
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};