<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ERD: tbl_overtime_request (ot_id PK, employee_id FK -> tbl_employee,
     *      approved_by FK -> tbl_employee [self-ref], ot_date, hours_requested,
     *      status)
     */
    public function up(): void
    {
        Schema::create('overtime_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('ot_id')->autoIncrement()->primary();
            $table->foreignId('employee_id')->constrained('employees', 'employee_id');
            $table->foreignId('approved_by')->nullable()->constrained('employees', 'employee_id');
            $table->date('ot_date')->nullable();
            $table->decimal('hours_requested', 5, 2)->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_requests');
    }
};