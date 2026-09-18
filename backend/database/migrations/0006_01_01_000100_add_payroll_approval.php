<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll run approval (Phase 8 — UC-08). TC-06 requires a payroll to stay
 * Draft "until explicitly approved"; who approved it, and when, is recorded
 * on each row. An approved row is final — recomputing the period leaves it
 * alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->foreignId('approved_by')->nullable()->after('status')->constrained('employees', 'employee_id');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn('approved_at');
        });
    }
};
