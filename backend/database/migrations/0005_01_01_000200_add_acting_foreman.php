<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Acting foreman cover and crew leadership history (Phase 7 — UC-06, TC-05).
 *
 * crews — the CURRENT cover, so a crew row alone says who leads it now and who
 * gets it back:
 *   regular_foreman_id  the foreman standing down; null when no cover is set
 *   acting_until        when the cover reverts on its own
 *
 * crew_assignments — the HISTORY, as TC-05 expects ("prior assignment history
 * is preserved through tbl_crew_assignment.status rather than being deleted").
 * Leadership becomes assignment rows beside the member rows:
 *   assignment_type  member | foreman | acting_foreman
 *   started_at       when this person began leading (leadership rows only)
 *   ended_at         when they stopped; the row's status becomes 'ended'
 *
 * Instants rather than date_assigned alone, because sync asks "did this
 * foreman lead the crew when the tap was captured?" — an offline tap taken
 * before a handover and synced after it is legitimate, one taken after is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crews', function (Blueprint $table) {
            $table->foreignId('regular_foreman_id')->nullable()->after('foreman_id')
                ->constrained('employees', 'employee_id');
            $table->timestamp('acting_until')->nullable()->after('regular_foreman_id')->index();
        });

        Schema::table('crew_assignments', function (Blueprint $table) {
            $table->string('assignment_type', 20)->default('member')->after('employee_id');
            $table->timestamp('started_at')->nullable()->after('status');
            $table->timestamp('ended_at')->nullable()->after('started_at');
            $table->index(['crew_id', 'assignment_type']);
        });

        // Today's foremen become the first leadership rows. Started when the
        // crew was created: no attendance for it can be older than that.
        $now = now();

        DB::table('crews')->whereNotNull('foreman_id')->orderBy('crew_id')->get()
            ->each(fn ($crew) => DB::table('crew_assignments')->insert([
                'crew_id' => $crew->crew_id,
                'employee_id' => $crew->foreman_id,
                'assignment_type' => 'foreman',
                'date_assigned' => $crew->deployed_at === null ? null : substr((string) $crew->deployed_at, 0, 10),
                'status' => 'active',
                'started_at' => $crew->created_at ?? $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
    }

    public function down(): void
    {
        DB::table('crew_assignments')->where('assignment_type', '!=', 'member')->delete();

        Schema::table('crew_assignments', function (Blueprint $table) {
            $table->dropIndex(['crew_id', 'assignment_type']);
            $table->dropColumn(['assignment_type', 'started_at', 'ended_at']);
        });

        Schema::table('crews', function (Blueprint $table) {
            $table->dropConstrainedForeignId('regular_foreman_id');
            $table->dropIndex(['acting_until']);
            $table->dropColumn('acting_until');
        });
    }
};
