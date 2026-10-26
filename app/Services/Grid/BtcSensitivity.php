<?php

namespace App\Services\Grid;

/**
 * Measures how a symbol moves with BTC, overall and during sudden BTC moves.
 */
class BtcSensitivity
{
    public const string Benchmark = 'BTCUSDT';

    /**
     * Compare a symbol's hourly returns with BTC's over the hours both have candles for.
     *
     * Shock hours are hours where BTC moved more than `btc_shock_hour_sigma` standard deviations.
     * When there are fewer than `min_shock_hours` of them in a direction, the shock beta for that
     * direction falls back to the overall beta.
     *
     * @param  list<array{open_time: int, close: float}>  $candles
     * @param  list<array{open_time: int, close: float}>  $btcCandles
     * @return array{beta: float, correlation: float, shock_beta_down: float, shock_beta_up: float, shock_hours_down: int, shock_hours_up: int, btc_daily_volatility_pct: float, btc_shock_pct: float}|null
     */
    public function measure(array $candles, array $btcCandles): ?array
    {
        $btcCloses = array_column($btcCandles, 'close', 'open_time');
        $closes = array_column($candles, 'close', 'open_time');
        $sharedTimes = array_values(array_intersect(array_keys($closes), array_keys($btcCloses)));
        sort($sharedTimes);

        if (count($sharedTimes) < GridAnalyzer::MinimumCandles) {
            return null;
        }

        $coinReturns = [];
        $btcReturns = [];

        for ($i = 1, $count = count($sharedTimes); $i < $count; $i++) {
            $coinReturns[] = log($closes[$sharedTimes[$i]] / $closes[$sharedTimes[$i - 1]]);
            $btcReturns[] = log($btcCloses[$sharedTimes[$i]] / $btcCloses[$sharedTimes[$i - 1]]);
        }

        $btcHourlyVolatility = Indicators::standardDeviation($btcReturns);
        $beta = Indicators::beta($btcReturns, $coinReturns);
        $threshold = (float) config('grid.btc_shock_hour_sigma') * $btcHourlyVolatility;
        $minimumShockHours = (int) config('grid.min_shock_hours');
        $down = ['btc' => [], 'coin' => []];
        $up = ['btc' => [], 'coin' => []];

        foreach ($btcReturns as $i => $btcReturn) {
            if ($btcReturn <= -$threshold) {
                $down['btc'][] = $btcReturn;
                $down['coin'][] = $coinReturns[$i];
            } elseif ($btcReturn >= $threshold) {
                $up['btc'][] = $btcReturn;
                $up['coin'][] = $coinReturns[$i];
            }
        }

        $btcDailyVolatility = $btcHourlyVolatility * sqrt(24);

        return [
            'beta' => round($beta, 3),
            'correlation' => round(Indicators::correlation($btcReturns, $coinReturns), 3),
            'shock_beta_down' => round(count($down['btc']) >= $minimumShockHours ? Indicators::betaThroughOrigin($down['btc'], $down['coin']) : $beta, 3),
            'shock_beta_up' => round(count($up['btc']) >= $minimumShockHours ? Indicators::betaThroughOrigin($up['btc'], $up['coin']) : $beta, 3),
            'shock_hours_down' => count($down['btc']),
            'shock_hours_up' => count($up['btc']),
            'btc_daily_volatility_pct' => round($btcDailyVolatility * 100, 3),
            'btc_shock_pct' => round((float) config('grid.btc_shock_multiple') * $btcDailyVolatility * 100, 3),
        ];
    }
}
