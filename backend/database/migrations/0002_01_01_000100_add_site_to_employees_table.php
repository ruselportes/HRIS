<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Employee's primary project site.
     *
     * The ERD links employees to sites through crews/crew_assignments; a live
     * "current site" denormalization is needed for the employee registry before
     * crew data exists (prototype requires a Project site on the 201 file and
     * surfaces it as a list column). Once crew assignment is authoritative in
     * Phase 3, HR screens may read the site from the current assignment instead;
     * this column then serves as the fallback/primary-site record.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('site_id')->nullable()->after('role_id')
                ->constrained('sites', 'site_id');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('site_id');
        });
    }
};