<?php

use App\Models\FuturesSymbol;
use App\Models\GridTrade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * @return array<string, mixed>
 */
function exchangeSymbol(string $symbol, string $baseAsset, string $status = 'TRADING', string $contractType = 'PERPETUAL'): array
{
    return [
        'symbol' => $symbol,
        'baseAsset' => $baseAsset,
        'quoteAsset' => 'USDT',
        'contractType' => $contractType,
        'status' => $status,
        'filters' => [['filterType' => 'PRICE_FILTER', 'tickSize' => '0.0010']],
    ];
}

it('stores the tradable USDT perpetuals including TradFi ones and marks delisted symbols without losing their trades', function () {
    Http::preventStrayRequests();
    Http::fake([
        'fapi.binance.com/fapi/v1/exchangeInfo' => Http::response(['symbols' => [
            exchangeSymbol('SOLUSDT', 'SOL'),
            exchangeSymbol('DOGEUSDT', 'DOGE'),
            exchangeSymbol('SOXSUSDT', 'SOXS', 'TRADING', 'TRADIFI_PERPETUAL'),
            exchangeSymbol('HALTUSDT', 'HALT', 'SETTLING'),
            exchangeSymbol('SOLUSDT_261225', 'SOL', 'TRADING', 'CURRENT_QUARTER'),
        ]]),
    ]);
    $delistedTrade = GridTrade::factory()->create(['symbol' => 'OLDUSDT']);

    $this->artisan('grid:sync-symbols')->assertSuccessful();

    expect(FuturesSymbol::query()->where('is_trading', true)->orderBy('symbol')->pluck('symbol')->all())->toBe(['DOGEUSDT', 'SOLUSDT', 'SOXSUSDT'])
        ->and(FuturesSymbol::query()->find('SOLUSDT'))
        ->base_asset->toBe('SOL')
        ->tick_size->toBe(0.001)
        ->and(FuturesSymbol::query()->find('OLDUSDT')->is_trading)->toBeFalse();

    $this->assertModelExists($delistedTrade);
});

it('keeps the known symbols when Binance cannot be reached', function () {
    Http::preventStrayRequests();
    Sleep::fake();
    Http::fake(['fapi.binance.com/*' => Http::response(['code' => -1000], 503)]);
    FuturesSymbol::factory()->create(['symbol' => 'SOLUSDT']);

    expect(fn () => $this->artisan('grid:sync-symbols'))->toThrow(Exception::class);

    expect(FuturesSymbol::query()->find('SOLUSDT')->is_trading)->toBeTrue();
});
