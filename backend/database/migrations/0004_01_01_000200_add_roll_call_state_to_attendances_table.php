<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5: the two columns roll call cannot work without.
     *
     * ERD tbl_attendance defines (attendance_id, employee_id, crew_id,
     * time_in, time_out, monotonic_timestamp, sync_status, override_flag) —
     * no date and no status. Both turn out to be load-bearing:
     *
     *   date: cannot be derived from time_in, because an Absent worker never
     *   clocked in and so has no time_in at all. Without a stored date an
     *   absence has no day attached to it, and "one row per employee per day"
     *   has no key to enforce.
     *
     *   status: the roll-call outcome the prototype actually captures is a
     *   three-way Present/Late/Absent choice (docs/prototypes/HRIS Foreman
     *   Attendance Mobile.dc.html). Nothing in the ERD can express Late, and
     *   inferring it from time_in against a shift start would bake the shift
     *   rule into every reader rather than recording what the foreman said.
     *
     * Additive, like Phase 3's crews.status/deployed_at, rather than a new
     * table. Still needs reflecting in the SDD §3.1 data dictionary and the
     * .drawio — Efren's ownership.
     */
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->date('date')->after('crew_id');
            $table->string('status', 20)->default('pending')->after('date');

            // One row per employee per day. The device sends an append-only
            // event log and several events collapse into this row, so the
            // ingestion path upserts against this key.
            $table->unique(['employee_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropUnique(['employee_id', 'date']);
            $table->dropColumn(['date', 'status']);
        });
    }
};
