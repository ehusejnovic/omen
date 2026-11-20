<?php

use App\Enums\GridStrategy;
use App\Models\GridScan;
use App\Services\Grid\VolatileGridScanner;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Raw Binance 5m kline rows for a pair that pumped from 100 to 130 and has settled into a tight box,
 * the last one still open.
 *
 * @return list<list<int|string>>
 */
function stabilizingKlineRows(int $count = 301): array
{
    $closeAt = fn (int $i): float => match (true) {
        $i < 200 => 100 + 0.3 * sin($i / 3),
        $i < 250 => 100 + 30 * ($i - 200) / 50 + 1.8 * sin($i * 1.1),
        default => 128 + 1.5 * sin(2 * M_PI * $i / 8),
    };
    $start = now()->subMinutes(5 * ($count - 1))->startOfMinute()->getTimestampMs();
    $rows = [];

    for ($i = 0; $i < $count; $i++) {
        $open = $closeAt($i);
        $close = $closeAt($i + 1);
        $spread = $i >= 200 && $i < 250 ? 0.012 : 0.002;
        $openTime = $start + $i * 300_000;
        $rows[] = [$openTime, (string) $open, (string) (max($open, $close) * (1 + $spread)), (string) (min($open, $close) * (1 - $spread)), (string) $close, '1000', $openTime + 299_999, '100000'];
    }

    return $rows;
}

/**
 * Raw Binance daily kline rows whose high-low range is `$rangePct` of the price, the last one still open.
 *
 * @return list<list<int|string>>
 */
function dailyKlineRows(float $rangePct, int $count = 15): array
{
    $start = now()->subDays($count - 1)->startOfDay()->getTimestampMs();
    $rows = [];

    for ($i = 0; $i < $count; $i++) {
        $openTime = $start + $i * 86_400_000;
        $rows[] = [$openTime, '100', (string) (100 + $rangePct / 2), (string) (100 - $rangePct / 2), '100', '1000', $openTime + 86_399_999, '100000'];
    }

    return $rows;
}

function volatileContract(string $symbol): array
{
    return [
        'symbol' => $symbol,
        'baseAsset' => str_replace('USDT', '', $symbol),
        'quoteAsset' => 'USDT',
        'contractType' => 'PERPETUAL',
        'status' => 'TRADING',
        'filters' => [
            ['filterType' => 'PRICE_FILTER', 'tickSize' => '0.001'],
            ['filterType' => 'MIN_NOTIONAL', 'notional' => '5'],
        ],
    ];
}

function volatileTicker(string $symbol, string $quoteVolume, string $highPrice, string $lowPrice): array
{
    return ['symbol' => $symbol, 'lastPrice' => '128', 'quoteVolume' => $quoteVolume, 'priceChangePercent' => '25', 'highPrice' => $highPrice, 'lowPrice' => $lowPrice];
}

/**
 * PUMPUSDT pumped and is stabilizing, CALMUSDT barely moves, and THINUSDT is volatile but illiquid.
 */
function fakeVolatileMarket(): void
{
    $stabilizing = stabilizingKlineRows();
    $closeOfLastClosed = $stabilizing[count($stabilizing) - 2][4];

    Http::fake([
        'fapi.binance.com/fapi/v1/exchangeInfo' => Http::response(['symbols' => [
            volatileContract('PUMPUSDT'),
            volatileContract('CALMUSDT'),
            volatileContract('THINUSDT'),
        ]]),
        'fapi.binance.com/fapi/v1/ticker/24hr' => Http::response([
            [...volatileTicker('PUMPUSDT', '400000000', '133', '99'), 'lastPrice' => $closeOfLastClosed],
            volatileTicker('CALMUSDT', '900000000', '102', '99'),
            volatileTicker('THINUSDT', '1000000', '160', '99'),
        ]),
        'fapi.binance.com/fapi/v1/premiumIndex' => Http::response([
            ['symbol' => 'PUMPUSDT', 'lastFundingRate' => '0.00010000'],
        ]),
        'fapi.binance.com/fapi/v1/klines?symbol=PUMPUSDT&interval=1d*' => Http::response(dailyKlineRows(14)),
        'fapi.binance.com/fapi/v1/klines?symbol=CALMUSDT&interval=1d*' => Http::response(dailyKlineRows(2)),
        'fapi.binance.com/fapi/v1/klines?symbol=PUMPUSDT&interval=5m*' => Http::response($stabilizing),
    ]);
}

it('stores short bot suggestions for liquid, volatile pairs that are stabilizing', function () {
    Http::preventStrayRequests();
    fakeVolatileMarket();

    $this->artisan('grid:scan --volatile')->assertSuccessful();

    $scan = GridScan::query()->sole();

    expect($scan->strategy)->toBe(GridStrategy::Volatile)
        ->and($scan->status)->toBe(GridScan::StatusCompleted)
        ->and($scan->symbols_scanned)->toBe(1)
        ->and($scan->suggestions_count)->toBe(1);

    $suggestion = $scan->suggestions()->sole();

    expect($suggestion->symbol)->toBe('PUMPUSDT')
        ->and($suggestion->funding_rate_pct)->toBe(0.01)
        ->and($suggestion->metrics['daily_atr_pct'])->toEqual(14.0)
        ->and($suggestion->metrics['runtime_minutes'])->toBe(120);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'symbol=CALMUSDT&interval=5m')
        || str_contains($request->url(), 'symbol=THINUSDT'));
});

it('reuses each pair\'s daily ATR for an hour instead of refetching daily candles every scan', function () {
    Http::preventStrayRequests();
    fakeVolatileMarket();
    $dailyRequests = fn (): int => Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'symbol=PUMPUSDT&interval=1d'))->count();

    $this->artisan('grid:scan --volatile')->assertSuccessful();
    $this->artisan('grid:scan --volatile')->assertSuccessful();

    expect($dailyRequests())->toBe(1);

    $this->travel(61)->minutes();
    $this->artisan('grid:scan --volatile')->assertSuccessful();

    expect($dailyRequests())->toBe(2);
});

it('runs alongside a swing scan but not alongside another volatile scan', function () {
    Http::preventStrayRequests();
    fakeVolatileMarket();
    Cache::lock(GridStrategy::Swing->lockName(), 900)->get();

    $this->artisan('grid:scan --volatile')->assertSuccessful();

    Cache::lock(GridStrategy::Volatile->lockName(), 300)->get();

    $this->artisan('grid:scan --volatile')->assertFailed();

    expect(GridScan::query()->count())->toBe(1);
});

it('marks the volatile scan as failed when Binance market data cannot be loaded', function () {
    Http::preventStrayRequests();
    Sleep::fake();
    Http::fake(['fapi.binance.com/*' => Http::response(['code' => -1000], 503)]);

    expect(fn () => app(VolatileGridScanner::class)->scan())->toThrow(Exception::class);

    $scan = GridScan::query()->sole();

    expect($scan->strategy)->toBe(GridStrategy::Volatile)
        ->and($scan->status)->toBe(GridScan::StatusFailed)
        ->and($scan->error)->not->toBeEmpty();
});

it('prunes volatile scans after a day while keeping swing scans for a week', function () {
    $volatile = GridScan::factory()->volatile()->create(['created_at' => now()->subHours(25)]);
    $recentVolatile = GridScan::factory()->volatile()->create(['created_at' => now()->subHours(23)]);
    $swing = GridScan::factory()->create(['created_at' => now()->subDays(6)]);
    $oldSwing = GridScan::factory()->create(['created_at' => now()->subDays(8)]);

    $this->artisan('model:prune', ['--model' => [GridScan::class]])->assertSuccessful();

    expect(GridScan::query()->pluck('id')->all())->toEqualCanonicalizing([$recentVolatile->id, $swing->id])
        ->and(GridScan::query()->find($volatile->id))->toBeNull()
        ->and(GridScan::query()->find($oldSwing->id))->toBeNull();
});
