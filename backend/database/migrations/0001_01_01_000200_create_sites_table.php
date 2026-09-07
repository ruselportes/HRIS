<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ERD: tbl_site (site_id PK, site_name, location, status)
     */
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->unsignedBigInteger('site_id')->autoIncrement()->primary();
            $table->string('site_name');
            $table->string('location')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};