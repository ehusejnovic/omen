<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('grid_scans', function (Blueprint $table) {
            $table->string('minimum_grade', 4)->nullable()->after('range_horizon_days');
            $table->string('mode_filter', 16)->nullable()->after('minimum_grade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('grid_scans', function (Blueprint $table) {
            $table->dropColumn(['minimum_grade', 'mode_filter']);
        });
    }
};
