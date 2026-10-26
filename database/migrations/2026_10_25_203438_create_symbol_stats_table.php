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
        Schema::create('symbol_stats', function (Blueprint $table) {
            $table->string('symbol', 32)->primary();
            $table->decimal('beta', 8, 3);
            $table->decimal('correlation', 6, 3);
            $table->decimal('shock_beta_down', 8, 3);
            $table->decimal('shock_beta_up', 8, 3);
            $table->unsignedSmallInteger('shock_hours_down');
            $table->unsignedSmallInteger('shock_hours_up');
            $table->decimal('btc_shock_pct', 8, 3);
            $table->timestamp('measured_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('symbol_stats');
    }
};
