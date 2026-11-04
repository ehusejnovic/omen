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
            $table->decimal('matched_profit', 18, 2)->nullable()->after('matched_trades');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('grid_trades', function (Blueprint $table) {
            $table->dropColumn('matched_profit');
        });
    }
};
