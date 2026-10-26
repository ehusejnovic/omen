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
        Schema::table('grid_suggestions', function (Blueprint $table) {
            $table->boolean('trailing_up')->default(false)->after('margin_allocation_pct');
            $table->boolean('trailing_down')->default(false)->after('trailing_up');
            $table->decimal('trailing_limit_price', 24, 10)->nullable()->after('trailing_down');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('grid_suggestions', function (Blueprint $table) {
            $table->dropColumn(['trailing_up', 'trailing_down', 'trailing_limit_price']);
        });
    }
};
