<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 7 — UC-05 Late Foreman Override, STD TC-04, and the HR review that
     * gates payroll.
     *
     * TC-04 says "one tbl_audit_log entry is written per override". So the
     * override EVENT is an audit_log row rather than a new table: one row per
     * foreman, crew and day, grouping every worker it credited. The Late
     * Override prototype asks for exactly that grouping — "HR judges one
     * decision instead of 38 rows" — so the review decision lives on the same
     * row. Additive and nullable: every other audit entry leaves these empty.
     *
     * attendances.captured_at keeps the real tap time server-side. Without it
     * a rejected override has nothing to fall back to, and TC-04's "the
     * credited time and the real time of entry are both recoverable" holds
     * only for the audit entry, not per worker.
     *
     * Still owed: SDD §3.1 data dictionary and the .drawio (Efren).
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignId('crew_id')->nullable()->after('actor_id')
                ->constrained('crews', 'crew_id');
            $table->date('subject_date')->nullable()->after('crew_id');

            $table->string('review_status', 20)->nullable()->after('timestamp');
            $table->foreignId('reviewed_by')->nullable()->after('review_status')
                ->constrained('employees', 'employee_id');
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('review_note')->nullable()->after('reviewed_at');

            $table->index(['action_type', 'review_status']);
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->timestamp('captured_at')->nullable()->after('time_in');
            $table->foreignId('override_audit_id')->nullable()->after('override_flag')
                ->constrained('audit_logs', 'audit_id');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('override_audit_id');
            $table->dropColumn('captured_at');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['action_type', 'review_status']);
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropConstrainedForeignId('crew_id');
            $table->dropColumn(['subject_date', 'review_status', 'reviewed_at', 'review_note']);
        });
    }
};
