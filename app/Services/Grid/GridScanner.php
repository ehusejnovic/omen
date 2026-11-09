<?php

namespace App\Services\Grid;

use App\Models\AppSetting;
use App\Models\GridScan;
use App\Models\SymbolStat;
use App\Services\Binance\BinanceFuturesClient;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Scans the most liquid USDT perpetuals and stores grid bot suggestions for the best-scoring symbols.
 */
class GridScanner
{
    public function __construct(
        private BinanceFuturesClient $binance,
        private GridAnalyzer $analyzer,
        private BtcSensitivity $btcSensitivity,
        private DailyTrend $dailyTrend,
    ) {}

    /**
     * Run a scan, or return null when another scan already holds the lock.
     *
     * @throws Throwable
     */
    public function scan(): ?GridScan
    {
        return Cache::lock('grid-scan', 900)->get(fn (): GridScan => $this->run()) ?: null;
    }

    /**
     * @throws Throwable
     */
    private function run(): GridScan
    {
        $spacingMultiplier = AppSetting::spacingMultiplier();
        $allowTrailing = AppSetting::allowsTrailing();
        $rangeHorizonDays = AppSetting::rangeHorizonDays();
        $scan = GridScan::create([
            'status' => GridScan::StatusRunning,
            'spacing_multiplier' => $spacingMultiplier,
            'range_horizon_days' => $rangeHorizonDays,
            'started_at' => now(),
        ]);

        try {
            $contracts = $this->binance->perpetualContracts();
            $tickers = $this->binance->tickers();
            $fundingRates = $this->binance->fundingRates();
            $excluded = config('grid.excluded_base_assets');

            $symbols = collect($contracts)
                ->reject(fn (array $contract): bool => in_array($contract['base_asset'], $excluded, true))
                ->keys()
                ->filter(fn (string $symbol): bool => isset($tickers[$symbol]))
                ->sortByDesc(fn (string $symbol): float => $tickers[$symbol]['quote_volume'])
                ->take(config('grid.symbols'))
                ->values()
                ->all();
            $symbols = array_values($symbols);

            $candles = $this->binance->closedCandles(
                array_values(array_unique([...$symbols, BtcSensitivity::Benchmark])),
                config('grid.kline_interval'),
                config('grid.kline_limit'),
                config('grid.concurrency'),
            );

            $dailyCandles = $this->binance->closedCandles(
                $symbols,
                '1d',
                config('grid.daily_trend.kline_limit'),
                config('grid.concurrency'),
            );

            $suggestions = [];
            $symbolStats = [];
            $btcCandles = $candles[BtcSensitivity::Benchmark] ?? [];

            foreach ($symbols as $symbol) {
                $sensitivity = $btcCandles === [] ? null : $this->btcSensitivity->measure($candles[$symbol] ?? [], $btcCandles);

                if ($sensitivity !== null) {
                    $symbolStats[] = [
                        'symbol' => $symbol,
                        ...collect($sensitivity)->except('btc_daily_volatility_pct')->all(),
                        'measured_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                $suggestion = $this->analyzer->analyze($symbol, $candles[$symbol] ?? [], [
                    ...$tickers[$symbol],
                    'funding_rate' => $fundingRates[$symbol] ?? 0.0,
                    'tick_size' => $contracts[$symbol]['tick_size'],
                    'min_notional' => $contracts[$symbol]['min_notional'],
                ], $spacingMultiplier, $allowTrailing, $sensitivity, $rangeHorizonDays, $this->dailyTrend->classify($dailyCandles[$symbol] ?? []));

                if ($suggestion !== null) {
                    $suggestions[] = $suggestion;
                }
            }

            $suggestions = collect($suggestions)
                ->sortBy([['score', 'desc'], ['quote_volume_24h', 'desc']])
                ->take(config('grid.results'))
                ->values()
                ->all();

            $scan->suggestions()->createMany($suggestions);

            SymbolStat::query()->upsert($symbolStats, ['symbol'], [
                'beta', 'correlation', 'shock_beta_down', 'shock_beta_up', 'shock_hours_down', 'shock_hours_up', 'btc_shock_pct', 'measured_at', 'updated_at',
            ]);

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

        return $scan;
    }
}
