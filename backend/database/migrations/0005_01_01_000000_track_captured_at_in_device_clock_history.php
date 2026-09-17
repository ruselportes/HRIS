<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 7: the clock baseline moves from time_in to captured_at.
     *
     * The late foreman override credits time_in as 07:00 while the tap really
     * happens at, say, 09:20. Compared against time_in, that is exactly the
     * shape of TC-01's clock rollback, so every override would be flagged and
     * kept out of payroll — TC-04 could never pass. captured_at is the real tap
     * time, so it is the reading the monotonic counter must agree with.
     *
     * Existing last_time_in values are copied across rather than dropped: before
     * overrides existed, time_in WAS the tap time, so it is a faithful baseline.
     * Added then dropped instead of renamed because RENAME COLUMN needs
     * MariaDB 10.5.2+, and the dev database is 10.4.
     */
    public function up(): void
    {
        Schema::table('device_keys', function (Blueprint $table) {
            $table->unsignedBigInteger('last_captured_at')->nullable()->after('last_monotonic_timestamp');
        });

        DB::table('device_keys')->update(['last_captured_at' => DB::raw('last_time_in')]);

        Schema::table('device_keys', function (Blueprint $table) {
            $table->dropColumn('last_time_in');
        });
    }

    public function down(): void
    {
        Schema::table('device_keys', function (Blueprint $table) {
            $table->unsignedBigInteger('last_time_in')->nullable()->after('last_monotonic_timestamp');
        });

        DB::table('device_keys')->update(['last_time_in' => DB::raw('last_captured_at')]);

        Schema::table('device_keys', function (Blueprint $table) {
            $table->dropColumn('last_captured_at');
        });
    }
};
