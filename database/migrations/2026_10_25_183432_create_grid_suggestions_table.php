<?php

use App\Models\GridScan;
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
        Schema::create('grid_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(GridScan::class)->constrained()->cascadeOnDelete();
            $table->string('symbol', 32);
            $table->string('mode', 16);
            $table->string('grade', 4);
            $table->unsignedTinyInteger('score');
            $table->decimal('last_price', 24, 10);
            $table->decimal('lower_price', 24, 10);
            $table->decimal('upper_price', 24, 10);
            $table->unsignedSmallInteger('grid_count');
            $table->string('grid_type', 16);
            $table->decimal('grid_spacing_pct', 8, 4);
            $table->decimal('profit_per_grid_pct', 8, 4);
            $table->unsignedTinyInteger('leverage');
            $table->decimal('margin_allocation_pct', 6, 2);
            $table->string('entry_trigger', 16);
            $table->decimal('entry_trigger_price', 24, 10)->nullable();
            $table->decimal('take_profit_pnl_pct', 8, 2);
            $table->decimal('stop_loss_pnl_pct', 8, 2);
            $table->decimal('estimated_daily_roi_pct', 8, 3);
            $table->decimal('quote_volume_24h', 24, 2);
            $table->decimal('price_change_24h_pct', 8, 2);
            $table->decimal('funding_rate_pct', 10, 5);
            $table->json('metrics');
            $table->json('reasons');
            $table->timestamps();

            $table->index(['grid_scan_id', 'score']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('grid_suggestions');
    }
};
