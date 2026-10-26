<?php

use App\Enums\GridMode;
use App\Models\AppSetting;
use App\Models\GridScan;
use App\Models\SymbolStat;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Raw Binance kline rows for a range-bound market, the last one still open.
 *
 * @return list<list<int|string>>
 */
function rangingKlineRows(int $count = 300, float $amplitude = 4): array
{
    $rows = [];
    $start = now()->subHours($count - 1)->startOfHour()->getTimestampMs();

    for ($i = 0; $i < $count; $i++) {
        $open = 100 + $amplitude * sin(2 * M_PI * $i / 24);
        $close = 100 + $amplitude * sin(2 * M_PI * ($i + 1) / 24);
        $openTime = $start + $i * 3_600_000;
        $rows[] = [$openTime, (string) $open, (string) (max($open, $close) + 0.2), (string) (min($open, $close) - 0.2), (string) $close, '1000', $openTime + 3_599_999, '100000'];
    }

    return $rows;
}

/**
 * Raw Binance kline rows for a strong, healthy uptrend, the last one still open.
 *
 * @return list<list<int|string>>
 */
function uptrendKlineRows(int $count = 480): array
{
    $priceAt = fn (int $hour): float => 100 * exp(0.0008 * $hour) + 2 * sin(2 * M_PI * $hour / 24) + 0.6 * sin($hour * 1.7);
    $start = now()->subHours($count - 1)->startOfHour()->getTimestampMs();
    $rows = [];

    for ($i = 0; $i < $count; $i++) {
        $open = $priceAt($i);
        $close = $priceAt($i + 1);
        $openTime = $start + $i * 3_600_000;
        $rows[] = [$openTime, (string) $open, (string) (max($open, $close) * 1.002), (string) (min($open, $close) * 0.998), (string) $close, '1000', $openTime + 3_599_999, '100000'];
    }

    return $rows;
}

function contract(string $symbol, string $baseAsset, string $contractType = 'PERPETUAL'): array
{
    return [
        'symbol' => $symbol,
        'baseAsset' => $baseAsset,
        'quoteAsset' => 'USDT',
        'contractType' => $contractType,
        'status' => 'TRADING',
        'filters' => [
            ['filterType' => 'PRICE_FILTER', 'tickSize' => '0.10'],
            ['filterType' => 'MIN_NOTIONAL', 'notional' => '100'],
        ],
    ];
}

function ticker(string $symbol, string $quoteVolume): array
{
    return ['symbol' => $symbol, 'lastPrice' => '100.0', 'quoteVolume' => $quoteVolume, 'priceChangePercent' => '1.5'];
}

function fakeBinanceMarket(): void
{
    Http::fake([
        'fapi.binance.com/fapi/v1/exchangeInfo' => Http::response(['symbols' => [
            contract('BTCUSDT', 'BTC'),
            contract('ETHUSDT', 'ETH'),
            contract('USDCUSDT', 'USDC'),
            contract('BTCUSDT_261225', 'BTC', 'CURRENT_QUARTER'),
        ]]),
        'fapi.binance.com/fapi/v1/ticker/24hr' => Http::response([
            ticker('BTCUSDT', '9000000000'),
            ticker('ETHUSDT', '5000000000'),
            ticker('USDCUSDT', '8000000000'),
            ticker('BTCUSDT_261225', '7000000000'),
        ]),
        'fapi.binance.com/fapi/v1/premiumIndex' => Http::response([
            ['symbol' => 'BTCUSDT', 'lastFundingRate' => '0.00010000'],
        ]),
        'fapi.binance.com/fapi/v1/klines?symbol=BTCUSDT*' => Http::response(rangingKlineRows()),
        'fapi.binance.com/fapi/v1/klines?symbol=ETHUSDT*' => Http::response(['code' => -1000], 500),
    ]);
}

it('stores a suggestion for each perpetual whose candles loaded', function () {
    Http::preventStrayRequests();
    fakeBinanceMarket();

    $this->artisan('grid:scan')->assertSuccessful();

    $scan = GridScan::query()->sole();

    expect($scan->status)->toBe(GridScan::StatusCompleted)
        ->and($scan->symbols_scanned)->toBe(2)
        ->and($scan->suggestions_count)->toBe(1)
        ->and($scan->finished_at)->not->toBeNull();

    $suggestion = $scan->suggestions()->sole();

    expect($suggestion->symbol)->toBe('BTCUSDT')
        ->and($suggestion->mode)->toBe(GridMode::Normal)
        ->and($suggestion->funding_rate_pct)->toBe(0.01)
        ->and($suggestion->metrics['min_notional'])->toEqual(100);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'symbol=USDCUSDT')
        || str_contains($request->url(), 'symbol=BTCUSDT_261225'));
});

it('marks the scan as failed when Binance market data cannot be loaded', function () {
    Http::preventStrayRequests();
    Sleep::fake();
    Http::fake(['fapi.binance.com/*' => Http::response(['code' => -1000], 503)]);

    expect(fn () => $this->artisan('grid:scan'))->toThrow(Exception::class);

    $scan = GridScan::query()->sole();

    expect($scan->status)->toBe(GridScan::StatusFailed)
        ->and($scan->error)->not->toBeEmpty()
        ->and($scan->suggestions()->count())->toBe(0);
});

it('does not start a second scan while one is running', function () {
    Http::preventStrayRequests();
    Cache::lock('grid-scan', 900)->get();

    $this->artisan('grid:scan')->assertFailed();

    expect(GridScan::query()->count())->toBe(0);
});

it('keeps only the highest-scoring suggestions', function () {
    Http::preventStrayRequests();
    config(['grid.results' => 1]);
    Http::fake([
        'fapi.binance.com/fapi/v1/exchangeInfo' => Http::response(['symbols' => [
            contract('FLATUSDT', 'FLAT'),
            contract('SWINGUSDT', 'SWING'),
        ]]),
        'fapi.binance.com/fapi/v1/ticker/24hr' => Http::response([
            ticker('FLATUSDT', '9000000000'),
            ticker('SWINGUSDT', '5000000000'),
        ]),
        'fapi.binance.com/fapi/v1/premiumIndex' => Http::response([]),
        'fapi.binance.com/fapi/v1/klines?symbol=FLATUSDT*' => Http::response(rangingKlineRows(amplitude: 0.05)),
        'fapi.binance.com/fapi/v1/klines?symbol=SWINGUSDT*' => Http::response(rangingKlineRows()),
    ]);

    $this->artisan('grid:scan')->assertSuccessful();

    $scan = GridScan::query()->sole();

    expect($scan->symbols_scanned)->toBe(2)
        ->and($scan->suggestions_count)->toBe(1)
        ->and($scan->suggestions()->pluck('symbol')->all())->toBe(['SWINGUSDT']);
});

it('records the spacing multiplier each scan was run with', function () {
    Http::preventStrayRequests();
    fakeBinanceMarket();
    AppSetting::setSpacingMultiplier(0.4);

    $this->artisan('grid:scan')->assertSuccessful();

    expect(GridScan::query()->sole()->spacing_multiplier)->toBe(0.4);
});

it('suggests trailing only while trailing suggestions are allowed', function (bool $allowed) {
    Http::preventStrayRequests();
    AppSetting::setAllowsTrailing($allowed);
    $rows = uptrendKlineRows();
    Http::fake([
        'fapi.binance.com/fapi/v1/exchangeInfo' => Http::response(['symbols' => [contract('UPUSDT', 'UP')]]),
        'fapi.binance.com/fapi/v1/ticker/24hr' => Http::response([
            ['symbol' => 'UPUSDT', 'lastPrice' => $rows[count($rows) - 2][4], 'quoteVolume' => '900000000', 'priceChangePercent' => '2'],
        ]),
        'fapi.binance.com/fapi/v1/premiumIndex' => Http::response([]),
        'fapi.binance.com/fapi/v1/klines*' => Http::response($rows),
    ]);

    $this->artisan('grid:scan')->assertSuccessful();

    $suggestion = GridScan::query()->sole()->suggestions()->sole();

    expect($suggestion->mode)->toBe(GridMode::Long)
        ->and($suggestion->trailing_up)->toBe($allowed)
        ->and($suggestion->trailing_limit_price !== null)->toBe($allowed);
})->with(['allowed' => [true], 'disallowed' => [false]]);

it('stores BTC sensitivity for scanned symbols, fetching BTC even when it is outside the top symbols', function () {
    Http::preventStrayRequests();
    config(['grid.symbols' => 1]);
    Http::fake([
        'fapi.binance.com/fapi/v1/exchangeInfo' => Http::response(['symbols' => [
            contract('BTCUSDT', 'BTC'),
            contract('SOLUSDT', 'SOL'),
        ]]),
        'fapi.binance.com/fapi/v1/ticker/24hr' => Http::response([
            ticker('BTCUSDT', '1000000000'),
            ticker('SOLUSDT', '9000000000'),
        ]),
        'fapi.binance.com/fapi/v1/premiumIndex' => Http::response([]),
        'fapi.binance.com/fapi/v1/klines*' => Http::response(rangingKlineRows()),
    ]);

    $this->artisan('grid:scan')->assertSuccessful();

    $stat = SymbolStat::query()->sole();

    expect(GridScan::query()->sole()->symbols_scanned)->toBe(1)
        ->and($stat->symbol)->toBe('SOLUSDT')
        ->and($stat->beta)->toBe(1.0)
        ->and($stat->correlation)->toBe(1.0)
        ->and(GridScan::query()->sole()->suggestions()->sole()->metrics['btc_beta'])->toEqual(1.0);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'symbol=BTCUSDT'));
});
