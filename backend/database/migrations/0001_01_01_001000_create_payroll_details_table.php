<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ERD: tbl_payroll_detail (detail_id PK, payroll_id FK -> tbl_payroll,
     *      regular_hours, overtime_hours, night_diff_hours, rest_day_hours,
     *      holiday_hours, deductions).
     * One payroll row maps to one detail row (1:1 per ERD), unique on payroll_id.
     */
    public function up(): void
    {
        Schema::create('payroll_details', function (Blueprint $table) {
            $table->unsignedBigInteger('detail_id')->autoIncrement()->primary();
            $table->foreignId('payroll_id')->unique()->constrained('payrolls', 'payroll_id');
            $table->decimal('regular_hours', 8, 2)->default(0);
            $table->decimal('overtime_hours', 8, 2)->default(0);
            $table->decimal('night_diff_hours', 8, 2)->default(0);
            $table->decimal('rest_day_hours', 8, 2)->default(0);
            $table->decimal('holiday_hours', 8, 2)->default(0);
            $table->decimal('deductions', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_details');
    }
};