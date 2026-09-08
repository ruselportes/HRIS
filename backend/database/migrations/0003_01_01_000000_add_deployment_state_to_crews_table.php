<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 3 (SPMP 4.4) deployment state for tbl_crew.
     *
     * The Phase 1 ERD figure only sketched (crew_id, site_id, foreman_id,
     * crew_name). The prototype's Draft / Deploy crew / "Without foreman"
     * states require persistence, so we extend the table here (deliberate,
     * documented deviation — see CLAUDE.md / SDD Phase 3 note).
     *
     * status value set (decision made once, here):
     *   - draft    (default) — created, roster may still be empty/no foreman
     *   - deployed           — set by POST /crews/{crew}/deploy, with deployed_at
     *   - archived           — RESERVED for a future disband flow (Phase 7);
     *                          no code path writes it in Phase 3.
     */
    public function up(): void
    {
        Schema::table('crews', function (Blueprint $table) {
            $table->string('status', 20)->default('draft')->after('crew_name');
            $table->timestamp('deployed_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('crews', function (Blueprint $table) {
            $table->dropColumn(['status', 'deployed_at']);
        });
    }
};
