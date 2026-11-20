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
            $table->string('strategy', 16)->default('swing')->after('status');
            $table->index(['strategy', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('grid_scans', function (Blueprint $table) {
            $table->dropIndex(['strategy', 'status']);
            $table->dropColumn('strategy');
        });
    }
};
