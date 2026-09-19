<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leave & Overtime Filing and Approval (Phase 9 — UC-10). The Phase 1 tables
 * carried only employee/approved_by/status; this adds the two-hop workflow:
 * filed_by (who submitted), the endorser the request is assigned to at filing
 * (assigned_endorser_id), who actually endorsed it, who approved it and when,
 * and a rejection with its note. cancelled has no actor column — cancellation
 * is filer-only and its record is the audit row.
 *
 * overtime_requests also gains batch_key (A3), so a foreman can file a week of
 * nights as one batch HR endorses and approves together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->foreignId('filed_by')->nullable()->after('employee_id')->constrained('employees', 'employee_id');
            $table->text('reason')->nullable()->after('leave_type');
            $table->foreignId('assigned_endorser_id')->nullable()->after('approved_by')->constrained('employees', 'employee_id');
            $table->foreignId('endorsed_by')->nullable()->after('assigned_endorser_id')->constrained('employees', 'employee_id');
            $table->timestamp('endorsed_at')->nullable()->after('endorsed_by');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->foreignId('rejected_by')->nullable()->after('approved_at')->constrained('employees', 'employee_id');
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            $table->text('rejection_note')->nullable()->after('rejected_at');
        });

        Schema::table('overtime_requests', function (Blueprint $table) {
            $table->foreignId('filed_by')->nullable()->after('employee_id')->constrained('employees', 'employee_id');
            $table->text('reason')->nullable()->after('hours_requested');
            $table->foreignId('assigned_endorser_id')->nullable()->after('approved_by')->constrained('employees', 'employee_id');
            $table->foreignId('endorsed_by')->nullable()->after('assigned_endorser_id')->constrained('employees', 'employee_id');
            $table->timestamp('endorsed_at')->nullable()->after('endorsed_by');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->foreignId('rejected_by')->nullable()->after('approved_at')->constrained('employees', 'employee_id');
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            $table->text('rejection_note')->nullable()->after('rejected_at');
            $table->string('batch_key', 64)->nullable()->after('rejection_note');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('filed_by');
            $table->dropConstrainedForeignId('assigned_endorser_id');
            $table->dropConstrainedForeignId('endorsed_by');
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropColumn(['reason', 'endorsed_at', 'approved_at', 'rejected_at', 'rejection_note']);
        });

        Schema::table('overtime_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('filed_by');
            $table->dropConstrainedForeignId('assigned_endorser_id');
            $table->dropConstrainedForeignId('endorsed_by');
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropColumn(['reason', 'endorsed_at', 'approved_at', 'rejected_at', 'rejection_note', 'batch_key']);
        });
    }
};
