<?php

namespace App\Models;

use Database\Factories\FuturesSymbolFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A Binance USDⓈ-M perpetual contract, synced from the exchange so journaled trades reference a real symbol.
 * Delisted symbols are kept, marked as not trading, so their journaled trades stay intact.
 *
 * @property string $symbol
 * @property string $base_asset
 * @property float|null $tick_size
 * @property bool $is_trading
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['symbol', 'base_asset', 'tick_size', 'is_trading'])]
class FuturesSymbol extends Model
{
    /** @use HasFactory<FuturesSymbolFactory> */
    use HasFactory;

    protected $primaryKey = 'symbol';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tick_size' => 'float',
            'is_trading' => 'boolean',
        ];
    }

    /**
     * Store the currently tradable contracts and mark every other known symbol as no longer trading.
     *
     * @param  array<string, array{base_asset: string, tick_size: float, min_notional: float}>  $contracts
     */
    public static function syncFromContracts(array $contracts): void
    {
        if ($contracts === []) {
            return;
        }

        $rows = collect($contracts)
            ->map(fn (array $contract, string $symbol): array => [
                'symbol' => $symbol,
                'base_asset' => $contract['base_asset'],
                'tick_size' => $contract['tick_size'] ?: null,
                'is_trading' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->values()
            ->all();

        foreach (array_chunk($rows, 200) as $chunk) {
            static::query()->upsert($chunk, ['symbol'], ['base_asset', 'tick_size', 'is_trading', 'updated_at']);
        }

        static::query()
            ->where('is_trading', true)
            ->whereNotIn('symbol', array_keys($contracts))
            ->update(['is_trading' => false, 'updated_at' => now()]);
    }

    /**
     * @return HasMany<GridTrade, $this>
     */
    public function gridTrades(): HasMany
    {
        return $this->hasMany(GridTrade::class, 'symbol', 'symbol');
    }
}
