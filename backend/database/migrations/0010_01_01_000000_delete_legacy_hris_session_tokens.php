<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Data-only: no schema change. Every token issued before the session
     * lifetimes (e240bb9) is named `hris-session` with no expiry, so it
     * outlives the 12-hour / 30-day rules, the daily prune never deletes it,
     * and the admin revoke cannot reach it (revoke deletes `mobile` tokens by
     * name) — the lost-phone gap the lifetimes meant to close. Deleting them
     * also forces the re-sign-in the session record already requires, instead
     * of hoping signed-in users do it.
     */
    public function up(): void
    {
        // The numbered prefix sorts before the timestamped migration that
        // creates personal_access_tokens, so on a fresh database this runs
        // first — where there is nothing to delete anyway. Only existing
        // deployments can hold legacy tokens.
        if (Schema::hasTable('personal_access_tokens')) {
            DB::table('personal_access_tokens')->where('name', 'hris-session')->delete();
        }
    }

    public function down(): void
    {
        // Deleted sessions cannot be restored; nothing to roll back.
    }
};
