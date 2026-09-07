<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ERD: tbl_payroll (payroll_id PK, employee_id FK -> tbl_employee,
     *      pay_period_start, pay_period_end, gross_pay, net_pay, status)
     */
    public function up(): void
    {
        Schema::create('payrolls', function (Blueprint $table) {
            $table->unsignedBigInteger('payroll_id')->autoIncrement()->primary();
            $table->foreignId('employee_id')->constrained('employees', 'employee_id');
            $table->date('pay_period_start')->nullable();
            $table->date('pay_period_end')->nullable();
            $table->decimal('gross_pay', 12, 2)->default(0);
            $table->decimal('net_pay', 12, 2)->default(0);
            $table->string('status')->default('draft');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payrolls');
    }
};