<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ERD: tbl_crew (crew_id PK, site_id FK -> tbl_site,
     *      foreman_id FK -> tbl_employee [self-ref], crew_name)
     */
    public function up(): void
    {
        Schema::create('crews', function (Blueprint $table) {
            $table->unsignedBigInteger('crew_id')->autoIncrement()->primary();
            $table->foreignId('site_id')->constrained('sites', 'site_id');
            $table->foreignId('foreman_id')->nullable()->constrained('employees', 'employee_id');
            $table->string('crew_name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crews');
    }
};