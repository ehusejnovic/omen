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
        Schema::create('futures_symbols', function (Blueprint $table) {
            $table->string('symbol', 32)->primary();
            $table->string('base_asset', 32);
            $table->decimal('tick_size', 24, 10)->nullable();
            $table->boolean('is_trading')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('futures_symbols');
    }
};
