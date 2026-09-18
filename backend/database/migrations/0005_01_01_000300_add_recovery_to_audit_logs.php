<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retroactive crew recovery (Phase 7 — UC-07).
 *
 * A recovery case is an audit_logs entry, like an override event: the entry
 * IS the record of what was reconstructed and who signed it. The engineer is
 * actor_id and their note the description; HR's second signature uses the
 * review columns Slice 2 added. The one thing missing was why the day had no
 * roll call, which the recovery queue filters by:
 *
 *   reason_code  a short code for why the entry exists (recovery cause:
 *                foreman_absent, phone_problem, records_rejected, no_work,
 *                other). Nullable and generic, unused by other entries.
 *
 * Plus an index for "is there already a case for this crew on this day?".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('reason_code', 40)->nullable()->after('review_note');
            $table->index(['crew_id', 'subject_date']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['crew_id', 'subject_date']);
            $table->dropColumn('reason_code');
        });
    }
};
