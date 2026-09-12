<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5: the device's last accepted clock reading.
     *
     * ClockIntegrityVerifier compares each record against the previous one
     * from the same device. Within a sync batch that comparison is
     * consecutive, but the FIRST record of a batch has to be compared against
     * the last record of the PREVIOUS batch — otherwise a rollback performed
     * between two syncs lands on the one record that is never checked.
     *
     * Sits alongside last_chain_hash, which exists for the same reason: chain
     * and clock continuity both have to survive across batches, not just
     * within one.
     */
    public function up(): void
    {
        Schema::table('device_keys', function (Blueprint $table) {
            $table->unsignedBigInteger('last_monotonic_timestamp')->nullable()->after('last_chain_hash');
            $table->unsignedBigInteger('last_time_in')->nullable()->after('last_monotonic_timestamp');
            $table->string('last_boot_id')->nullable()->after('last_time_in');
        });
    }

    public function down(): void
    {
        Schema::table('device_keys', function (Blueprint $table) {
            $table->dropColumn(['last_monotonic_timestamp', 'last_time_in', 'last_boot_id']);
        });
    }
};
