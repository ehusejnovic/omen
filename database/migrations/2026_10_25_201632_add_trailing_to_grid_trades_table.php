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
            $table->boolean('trailing_up')->default(false)->after('grid_count');
            $table->boolean('trailing_down')->default(false)->after('trailing_up');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('grid_trades', function (Blueprint $table) {
            $table->dropColumn(['trailing_up', 'trailing_down']);
        });
    }
};
