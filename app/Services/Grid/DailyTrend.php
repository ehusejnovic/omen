<?php

namespace App\Services\Grid;

use Illuminate\Support\Arr;

/**
 * Classifies a coin's daily trend from daily candles, so grids aren't run against it.
 */
class DailyTrend
{
    public const string Bearish = 'bearish';

    public const string Bullish = 'bullish';

    public const string Neutral = 'neutral';

    /**
     * Classify closed daily candles (oldest first), or return null when there is too little history.
     *
     * @param  list<array{close: float}>  $candles
     * @return array{trend: string, ema_fast: float, ema_slow: float, ema_fast_slope_pct: float, close_vs_ema_fast_pct: float, return_30d_pct: float|null}|null
     */
    public function classify(array $candles): ?array
    {
        if (count($candles) < (int) config('grid.daily_trend.min_candles')) {
            return null;
        }

        $closes = array_column($candles, 'close');
        $close = Arr::last($closes);
        $fastSeries = Indicators::ema($closes, (int) config('grid.daily_trend.fast_ema'));
        $fast = Arr::last($fastSeries);
        $slow = Arr::last(Indicators::ema($closes, (int) config('grid.daily_trend.slow_ema')));
        $fastBefore = $fastSeries[count($fastSeries) - 1 - (int) config('grid.daily_trend.slope_days')];
        $slopePct = ($fast / $fastBefore - 1) * 100;
        $minimumSlope = (float) config('grid.daily_trend.min_trend_slope_pct');

        $trend = match (true) {
            $close < $fast && $fast < $slow && $slopePct <= -$minimumSlope => self::Bearish,
            $close > $fast && $fast > $slow && $slopePct >= $minimumSlope => self::Bullish,
            default => self::Neutral,
        };

        return [
            'trend' => $trend,
            'ema_fast' => $fast,
            'ema_slow' => $slow,
            'ema_fast_slope_pct' => round($slopePct, 2),
            'close_vs_ema_fast_pct' => round(($close / $fast - 1) * 100, 2),
            'return_30d_pct' => count($closes) > 30 ? round(($close / $closes[count($closes) - 31] - 1) * 100, 2) : null,
        ];
    }
}
