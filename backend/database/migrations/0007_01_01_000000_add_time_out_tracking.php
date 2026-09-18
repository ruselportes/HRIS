<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Time-out capture (payload v3). attendances.time_out has existed since the
 * ERD; nothing wrote to it until now.
 *
 *   time_out_type          null for an "Out" tapped as the worker left,
 *                          shift_end for a Close-shift credit, manual_time for
 *                          a time the foreman stated
 *   time_out_captured_at   when the time-out was actually entered — what a
 *                          rejected manual time-out falls back to, as a
 *                          rejected time-in credit falls back to captured_at
 *   time_out_audit_id      the HR review of a manual time-out. Separate from
 *                          override_audit_id: one record can carry a late-start
 *                          credit on its time in AND a manual time-out, and
 *                          each needs its own review.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->string('time_out_type', 20)->nullable()->after('time_out');
            $table->timestamp('time_out_captured_at')->nullable()->after('time_out_type');
            $table->foreignId('time_out_audit_id')->nullable()->after('override_audit_id')
                ->constrained('audit_logs', 'audit_id');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('time_out_audit_id');
            $table->dropColumn(['time_out_type', 'time_out_captured_at']);
        });
    }
};
