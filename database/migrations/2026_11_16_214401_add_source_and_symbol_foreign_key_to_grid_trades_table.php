<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Symbols already journaled are registered as not trading until the next Binance sync confirms them,
     * and trades with a suggested grade are taken to have come from the scanner.
     */
    public function up(): void
    {
        $journaledSymbols = DB::table('grid_trades')->distinct()->pluck('symbol')
            ->map(fn (string $symbol): array => [
                'symbol' => $symbol,
                'base_asset' => Str::beforeLast($symbol, 'USDT') ?: $symbol,
                'is_trading' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->all();

        DB::table('futures_symbols')->insertOrIgnore($journaledSymbols);

        Schema::table('grid_trades', function (Blueprint $table) {
            $table->string('source', 16)->default('manual')->after('mode');
            $table->foreign('symbol')->references('symbol')->on('futures_symbols')->restrictOnDelete();
        });

        DB::table('grid_trades')->whereNotNull('suggested_grade')->update(['source' => 'scanner']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('grid_trades', function (Blueprint $table) {
            $table->dropForeign(['symbol']);
            $table->dropColumn('source');
        });
    }
};
