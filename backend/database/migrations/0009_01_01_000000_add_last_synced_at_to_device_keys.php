<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Device & Sync Health (Phase 10) — the one piece of sync state the
     * integrity engine never recorded: when the server LAST heard from a
     * device, as opposed to what it heard. The clock-history columns
     * (last_captured_at, last_monotonic_timestamp, last_boot_id) describe the
     * most recent TRUSTED event; a device that only ever sends rejected or
     * refused batches never advances them, yet it is visibly online. That is
     * the difference this column captures: last contact, not last success.
     *
     * Server receive time, bumped on every ingest — accepted, flagged, refused
     * or rejected alike — because all four mean the device reached out.
     *
     * Like `device_keys` itself, this is a documented addition the original
     * ERD never modelled (tbl_attendance_sync_queue has a queued_at but is
     * never written by the app). Needs reflecting in the SDD §3.1 data
     * dictionary and HRIS_ERD.drawio before submission — Efren's ownership.
     */
    public function up(): void
    {
        Schema::table('device_keys', function (Blueprint $table) {
            $table->timestamp('last_synced_at')->nullable()->after('bound_at');
        });
    }

    public function down(): void
    {
        Schema::table('device_keys', function (Blueprint $table) {
            $table->dropColumn('last_synced_at');
        });
    }
};
