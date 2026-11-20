<?php

namespace App\Services\Grid;

use App\Enums\GridStrategy;
use App\Models\GridScan;
use App\Services\Binance\BinanceFuturesClient;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Scans liquid USDT perpetuals with a large daily ATR for pairs that are stabilizing, and stores short
 * grid bot suggestions for them.
 */
class VolatileGridScanner
{
    public const string DailyAtrCacheKey = 'grid-volatile:daily-atr';

    public function __construct(
        private BinanceFuturesClient $binance,
        private VolatileGridAnalyzer $analyzer,
        private SetupAlerts $setupAlerts,
    ) {}

    /**
     * Run a scan, or return null when another volatile scan already holds the lock.
     *
     * @throws Throwable
     */
    public function scan(): ?GridScan
    {
        return Cache::lock(GridStrategy::Volatile->lockName(), 300)->get(fn (): GridScan => $this->run()) ?: null;
    }

    /**
     * @throws Throwable
     */
    private function run(): GridScan
    {
        $config = config('grid.volatile');
        $scan = GridScan::create([
            'status' => GridScan::StatusRunning,
            'strategy' => GridStrategy::Volatile,
            'started_at' => now(),
        ]);

        try {
            $contracts = $this->binance->perpetualContracts();
            $tickers = $this->binance->tickers();
            $excluded = config('grid.excluded_base_assets');

            $liquid = collect($contracts)
                ->reject(fn (array $contract): bool => in_array($contract['base_asset'], $excluded, true))
                ->keys()
                ->filter(fn (string $symbol): bool => isset($tickers[$symbol]) && $tickers[$symbol]['quote_volume'] >= $config['min_quote_volume'])
                ->sortByDesc(fn (string $symbol): float => $tickers[$symbol]['quote_volume'])
                ->take($config['symbols'])
                ->values()
                ->all();

            $dailyAtr = $this->dailyAtrPct($liquid);

            $symbols = array_values(array_filter($liquid, fn (string $symbol): bool => ($dailyAtr[$symbol] ?? 0) >= $config['min_daily_atr_pct']
                || $this->dayRangePct($tickers[$symbol]) >= $config['min_day_range_pct']));

            $suggestions = [];

            if ($symbols !== []) {
                $fundingRates = $this->binance->fundingRates();
                $candles = $this->binance->closedCandles($symbols, '5m', $config['kline_limit'], config('grid.concurrency'));

                foreach ($symbols as $symbol) {
                    $suggestion = $this->analyzer->analyze($symbol, $candles[$symbol] ?? [], [
                        ...$tickers[$symbol],
                        'funding_rate' => $fundingRates[$symbol] ?? 0.0,
                        'tick_size' => $contracts[$symbol]['tick_size'],
                        'min_notional' => $contracts[$symbol]['min_notional'],
                    ], $dailyAtr[$symbol] ?? null);

                    if ($suggestion !== null) {
                        $suggestions[] = $suggestion;
                    }
                }
            }

            $suggestions = collect($suggestions)
                ->sortBy([['score', 'desc'], ['quote_volume_24h', 'desc']])
                ->take($config['results'])
                ->values()
                ->all();

            $scan->suggestions()->createMany($suggestions);

            $scan->update([
                'status' => GridScan::StatusCompleted,
                'symbols_scanned' => count($symbols),
                'suggestions_count' => count($suggestions),
                'finished_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $scan->update([
                'status' => GridScan::StatusFailed,
                'error' => $exception->getMessage(),
                'finished_at' => now(),
            ]);

            throw $exception;
        }

        $this->setupAlerts->notify($scan);

        return $scan;
    }

    /**
     * 14-day ATR of closed daily candles as a percentage of the last close, keyed by symbol, null for
     * symbols with too little daily history. Closed daily candles change once a day, so each symbol's
     * value is cached for an hour instead of refetched every scan.
     *
     * @param  list<string>  $symbols
     * @return array<string, float|null>
     */
    private function dailyAtrPct(array $symbols): array
    {
        $dailyAtr = [];
        $missing = [];

        foreach ($symbols as $symbol) {
            $cached = Cache::get(self::DailyAtrCacheKey.":{$symbol}");

            if (is_array($cached)) {
                $dailyAtr[$symbol] = $cached['atr_pct'];
            } else {
                $missing[] = $symbol;
            }
        }

        if ($missing === []) {
            return $dailyAtr;
        }

        foreach ($this->binance->closedCandles($missing, '1d', config('grid.volatile.daily_kline_limit'), config('grid.concurrency')) as $symbol => $candles) {
            $atrPct = null;

            if (count($candles) >= 3) {
                $closes = array_column($candles, 'close');
                $atr = Indicators::atr(array_column($candles, 'high'), array_column($candles, 'low'), $closes, min(14, count($candles) - 1));
                $atrPct = $atr / end($closes) * 100;
            }

            $dailyAtr[$symbol] = $atrPct;
            Cache::put(self::DailyAtrCacheKey.":{$symbol}", ['atr_pct' => $atrPct], now()->addHour());
        }

        return $dailyAtr;
    }

    /**
     * @param  array{high_price: float, low_price: float}  $ticker
     */
    private function dayRangePct(array $ticker): float
    {
        return $ticker['low_price'] > 0 ? ($ticker['high_price'] / $ticker['low_price'] - 1) * 100 : 0.0;
    }
}
