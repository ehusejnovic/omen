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
        Schema::table('grid_trades', function (Blueprint $table) {
            $table->unsignedTinyInteger('range_horizon_days')->nullable()->after('trailing_down');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('grid_trades', function (Blueprint $table) {
            $table->dropColumn('range_horizon_days');
        });
    }
};
