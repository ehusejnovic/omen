<?php

namespace App\Services\Grid;

use App\Enums\EntryTrigger;
use App\Enums\GridGrade;
use App\Enums\GridMode;
use Illuminate\Support\Arr;

/**
 * Turns one highly volatile symbol's 5m candles into a short grid bot (up to a few hours) once the
 * symbol has stopped trending and settled into a range.
 *
 * The bot starts at the fair value of the recent stabilization box, so the start price is the main
 * output: when the price has drifted away from it, the bot waits for an entry trigger. The range is
 * centered on the start price and sized to the expected move over the runtime.
 *
 * All PnL figures are expressed as a share of the bot's margin, the way Binance reports grid ROI.
 */
class VolatileGridAnalyzer
{
    public const int MinimumCandles = 144;

    private const int CandlesPerHour = 12;

    private const int CandlesPerDay = 288;

    private const int CandleMinutes = 5;

    /** Candles of recent returns that measure the volatility the range is sized from (6 hours). */
    private const int SigmaCandles = 72;

    /**
     * Analyze a symbol, returning suggestion attributes or null when the symbol is not volatile
     * enough, is not stabilizing yet, a range breakout would lose more than its budget at 1x, or the
     * grid is not expected to earn enough over the runtime to be worth starting.
     *
     * @param  list<array{open: float, high: float, low: float, close: float}>  $candles  Closed 5m candles, oldest first.
     * @param  array{last_price: float, quote_volume: float, price_change_pct: float, funding_rate: float, tick_size: float, min_notional: float}  $market
     * @param  float|null  $dailyAtrPct  14-day ATR of daily candles as a percentage of price; null for symbols without enough daily history.
     * @return array<string, mixed>|null
     */
    public function analyze(string $symbol, array $candles, array $market, ?float $dailyAtrPct): ?array
    {
        if (count($candles) < self::MinimumCandles || $market['last_price'] <= 0) {
            return null;
        }

        $config = config('grid.volatile');
        $fee = (float) config('grid.maker_fee');
        $runtimeMinutes = (int) $config['runtime_minutes'];
        $windowCandles = max(6, intdiv($runtimeMinutes, self::CandleMinutes));
        $price = $market['last_price'];

        $closes = array_column($candles, 'close');
        $highs = array_column($candles, 'high');
        $lows = array_column($candles, 'low');
        $dayHighs = array_slice($highs, -self::CandlesPerDay);
        $dayLows = array_slice($lows, -self::CandlesPerDay);
        $dayHigh = max($dayHighs);
        $dayLow = min($dayLows);

        if ($dayLow <= 0 || $dayHigh <= $dayLow) {
            return null;
        }

        $dayRangePct = ($dayHigh / $dayLow - 1) * 100;

        if (($dailyAtrPct ?? 0) < (float) $config['min_daily_atr_pct'] && $dayRangePct < (float) $config['min_day_range_pct']) {
            return null;
        }

        $contraction = $this->contractionRatio(array_slice(Indicators::trueRanges($highs, $lows, $closes), -self::CandlesPerDay));
        $window = array_slice($candles, -$windowCandles);
        $boxHigh = max(array_column($window, 'high'));
        $boxLow = min(array_column($window, 'low'));
        $boxWidth = $boxHigh - $boxLow;
        $boxRatio = $boxWidth / ($dayHigh - $dayLow);

        if ($contraction > (float) $config['max_contraction_ratio'] || $boxRatio > (float) $config['max_box_ratio']) {
            return null;
        }

        $atr = Indicators::atr($highs, $lows, $closes);
        $atrPct = $atr / $price;
        $sigma = Indicators::standardDeviation(Indicators::logReturns(array_slice($closes, -self::SigmaCandles - 1))) * sqrt($windowCandles);

        if ($sigma <= 0) {
            return null;
        }

        $windowCloses = array_column($window, 'close');
        $fastAverage = Indicators::ema($closes, 20);
        $fastTrend = Arr::last($fastAverage);
        $slowTrend = Arr::last(Indicators::ema($closes, 50));
        $lean = Indicators::slope(array_map('log', $windowCloses)) * $windowCandles / $sigma;
        $efficiency = Indicators::efficiencyRatio($windowCloses);
        $crossings = Indicators::crossings($windowCloses, array_slice($fastAverage, -$windowCandles));
        $path = array_sum(array_map('abs', Indicators::logReturns(array_slice($closes, -$windowCandles - 1))));
        $fairValue = array_sum(array_map(fn (array $candle): float => ($candle['high'] + $candle['low'] + $candle['close']) / 3, $window)) / count($window);

        $mode = $this->mode($lean, $efficiency, $fastTrend, $slowTrend);
        $tickSize = $market['tick_size'] > 0 ? $market['tick_size'] : $price / 1e6;

        [$trigger, $startPrice] = $this->start($mode, $price, $fairValue, $boxLow, $boxHigh, $atr);
        $startPrice = $trigger === EntryTrigger::Immediate ? $price : round($startPrice / $tickSize) * $tickSize;

        [$lower, $upper] = $this->range(
            $startPrice,
            $sigma,
            Indicators::percentile($dayLows, 2),
            Indicators::percentile($dayHighs, 98),
            max($tickSize, $boxLow - $atr),
            $boxHigh + $atr,
        );
        $lower = max($tickSize, floor($lower / $tickSize) * $tickSize);
        $upper = ceil($upper / $tickSize) * $tickSize;

        $targetSpacing = $this->clamp(
            $atrPct * (float) $config['spacing_multiplier'],
            2 * $fee + (float) config('grid.min_net_grid_profit'),
            (float) $config['max_grid_spacing'],
        );
        $gridCount = (int) round(log($upper / $lower) / log(1 + $targetSpacing));
        $gridCount = max((int) config('grid.min_grids'), min((int) config('grid.max_grids'), $gridCount));
        $levels = GridLevels::prices($lower, $upper, $gridCount, true);
        $spacing = ($upper / $lower) ** (1 / $gridCount) - 1;
        $netGridProfit = $spacing - 2 * $fee;

        $breakoutBuffer = 1.5 * $atr;
        $breakoutDown = max($tickSize, $lower - $breakoutBuffer);
        $breakoutUp = $upper + $breakoutBuffer;
        $qtyPerGrid = 1 / ($gridCount * $startPrice);
        $lossAtOneX = match ($mode) {
            GridMode::Long => -GridLevels::unrealizedPnl($mode, $levels, $startPrice, $breakoutDown, $qtyPerGrid),
            GridMode::Short => -GridLevels::unrealizedPnl($mode, $levels, $startPrice, $breakoutUp, $qtyPerGrid),
            GridMode::Normal => max(
                -GridLevels::unrealizedPnl($mode, $levels, $startPrice, $breakoutDown, $qtyPerGrid),
                -GridLevels::unrealizedPnl($mode, $levels, $startPrice, $breakoutUp, $qtyPerGrid),
            ),
        };
        $lossAtOneX = max($lossAtOneX, 0.001);
        $maxStopLoss = (float) $config['max_stop_loss'];

        if ($lossAtOneX > $maxStopLoss) {
            return null;
        }

        $leverage = max(1, min((int) $config['max_leverage'], (int) floor($maxStopLoss / $lossAtOneX)));
        $stopLoss = $lossAtOneX * $leverage;

        $exposure = match ($mode) {
            GridMode::Long => 0.5,
            GridMode::Short => -0.5,
            GridMode::Normal => 0.0,
        };
        $roundTrips = $path / $spacing / 2 * 0.75;
        $runtimeRoi = $roundTrips * ($leverage / $gridCount) * $netGridProfit
            - $market['funding_rate'] * $leverage * $exposure * $runtimeMinutes / 480;
        $trendProfit = max(0.0, match ($mode) {
            GridMode::Long => GridLevels::unrealizedPnl($mode, $levels, $startPrice, $boxHigh, $qtyPerGrid) * $leverage,
            GridMode::Short => GridLevels::unrealizedPnl($mode, $levels, $startPrice, $boxLow, $qtyPerGrid) * $leverage,
            GridMode::Normal => 0.0,
        });

        if ($runtimeRoi < (float) $config['min_runtime_roi']) {
            return null;
        }

        $takeProfit = $this->clamp(1.5 * $runtimeRoi + 0.5 * $trendProfit, (float) $config['min_take_profit'], (float) $config['max_take_profit']);

        $adverseFunding = $market['funding_rate'] * $exposure * 2;
        $volatilityPct = $dailyAtrPct ?? $dayRangePct;
        $breakdown = [
            'volatility' => 15 * $this->band($volatilityPct, 5, 8, 30, 80),
            'contraction' => 25 * $this->clamp(((float) $config['max_contraction_ratio'] - $contraction) / ((float) $config['max_contraction_ratio'] - 0.2)),
            'box' => 20 * $this->clamp(((float) $config['max_box_ratio'] - $boxRatio) / ((float) $config['max_box_ratio'] - 0.1)),
            'chop' => 15 * (0.6 * $this->clamp($crossings / 6) + 0.4 * $this->clamp((0.6 - $efficiency) / 0.4)),
            'liquidity' => 10 * $this->clamp((log10(max($market['quote_volume'], 1)) - log10(2e7)) / (log10(5e8) - log10(2e7))),
            'profitability' => 15 * $this->clamp($runtimeRoi / 0.01),
            'funding' => $adverseFunding > 0.001 ? -5.0 : 0.0,
        ];
        $score = (int) round(max(0, min(100, array_sum($breakdown))));
        $grade = GridGrade::fromScore($score);

        return [
            'symbol' => $symbol,
            'mode' => $mode,
            'grade' => $grade,
            'score' => $score,
            'last_price' => $price,
            'lower_price' => $lower,
            'upper_price' => $upper,
            'grid_count' => $gridCount,
            'grid_type' => 'geometric',
            'grid_spacing_pct' => round($spacing * 100, 4),
            'profit_per_grid_pct' => round($netGridProfit * 100, 4),
            'leverage' => $leverage,
            'margin_allocation_pct' => $grade->marginAllocationPct(),
            'trailing_up' => false,
            'trailing_down' => false,
            'trailing_limit_price' => null,
            'entry_trigger' => $trigger,
            'entry_trigger_price' => $trigger === EntryTrigger::Immediate ? null : $startPrice,
            'take_profit_pnl_pct' => round($takeProfit * 100, 2),
            'stop_loss_pnl_pct' => round(-$stopLoss * 100, 2),
            'estimated_daily_roi_pct' => round($runtimeRoi * 1440 / $runtimeMinutes * 100, 3),
            'quote_volume_24h' => round($market['quote_volume'], 2),
            'price_change_24h_pct' => round($market['price_change_pct'], 2),
            'funding_rate_pct' => round($market['funding_rate'] * 100, 5),
            'metrics' => [
                'runtime_minutes' => $runtimeMinutes,
                'runtime_roi_pct' => round($runtimeRoi * 100, 3),
                'start_price' => $startPrice,
                'fair_value' => $fairValue,
                'daily_atr_pct' => $dailyAtrPct === null ? null : round($dailyAtrPct, 2),
                'day_range_pct' => round($dayRangePct, 2),
                'day_low' => $dayLow,
                'day_high' => $dayHigh,
                'box_low' => $boxLow,
                'box_high' => $boxHigh,
                'box_ratio' => round($boxRatio, 3),
                'contraction_ratio' => round($contraction, 3),
                'atr_pct' => round($atrPct * 100, 3),
                'runtime_sigma_pct' => round($sigma * 100, 2),
                'efficiency_ratio' => round($efficiency, 3),
                'crossings' => $crossings,
                'lean_signal' => round($lean, 2),
                'breakout_down_price' => $breakoutDown,
                'breakout_up_price' => $breakoutUp,
                'tick_size' => $tickSize,
                'min_notional' => $market['min_notional'],
                'score_breakdown' => array_map(fn (float|int $points): float => round($points, 1), $breakdown),
            ],
            'reasons' => $this->reasons($mode, $volatilityPct, $dailyAtrPct !== null, $dayRangePct, $contraction, $boxRatio, $crossings, $trigger, $fairValue, $tickSize, $runtimeMinutes, $netGridProfit, $adverseFunding),
        ];
    }

    /**
     * Average true range of the latest hour divided by that of the busiest hour in the given true ranges.
     *
     * @param  list<float>  $trueRanges
     */
    private function contractionRatio(array $trueRanges): float
    {
        $hourlyRanges = [];

        for ($i = self::CandlesPerHour, $count = count($trueRanges); $i <= $count; $i++) {
            $hourlyRanges[] = array_sum(array_slice($trueRanges, $i - self::CandlesPerHour, self::CandlesPerHour)) / self::CandlesPerHour;
        }

        $peak = max($hourlyRanges);

        return $peak > 0 ? end($hourlyRanges) / $peak : 1.0;
    }

    /**
     * Stay neutral unless the last runtime window leans clearly one way and the short-term averages agree.
     */
    private function mode(float $lean, float $efficiency, float $fastTrend, float $slowTrend): GridMode
    {
        $threshold = (float) config('grid.volatile.lean_signal');
        $directional = $efficiency >= (float) config('grid.volatile.lean_efficiency');

        return match (true) {
            $directional && $lean >= $threshold && $fastTrend > $slowTrend => GridMode::Long,
            $directional && $lean <= -$threshold && $fastTrend < $slowTrend => GridMode::Short,
            default => GridMode::Normal,
        };
    }

    /**
     * Where the bot should start: a neutral bot at the box's fair value, a long bot in the lower part
     * of the box and a short bot in the upper part. When the price is away from that zone, the bot waits
     * for an entry trigger at its edge.
     *
     * @return array{0: EntryTrigger, 1: float}
     */
    private function start(GridMode $mode, float $price, float $fairValue, float $boxLow, float $boxHigh, float $atr): array
    {
        $boxWidth = $boxHigh - $boxLow;
        $tolerance = max($atr, 0.15 * $boxWidth);
        $longEntry = $boxLow + 0.35 * $boxWidth;
        $shortEntry = $boxHigh - 0.35 * $boxWidth;

        return match (true) {
            $mode === GridMode::Normal && $price > $fairValue + $tolerance => [EntryTrigger::PriceBelow, $fairValue + $tolerance],
            $mode === GridMode::Normal && $price < $fairValue - $tolerance => [EntryTrigger::PriceAbove, $fairValue - $tolerance],
            $mode === GridMode::Long && $price > $longEntry + $atr => [EntryTrigger::PriceBelow, $longEntry],
            $mode === GridMode::Short && $price < $shortEntry - $atr => [EntryTrigger::PriceAbove, $shortEntry],
            default => [EntryTrigger::Immediate, $price],
        };
    }

    /**
     * Range around the start price: each side reaches toward the day's extremes, but stays between the
     * configured multiples of the expected move over the runtime and always contains the stabilization box.
     *
     * @return array{0: float, 1: float}
     */
    private function range(float $startPrice, float $sigma, float $dayFloor, float $dayCeiling, float $boxFloor, float $boxCeiling): array
    {
        $minSide = (float) config('grid.volatile.range_sigma_min') * $sigma;
        $maxSide = (float) config('grid.volatile.range_sigma_max') * $sigma;

        $down = max($this->clamp(log($startPrice / $dayFloor), $minSide, $maxSide), log($startPrice / $boxFloor));
        $up = max($this->clamp(log($dayCeiling / $startPrice), $minSide, $maxSide), log($boxCeiling / $startPrice));

        return [$startPrice * exp(-$down), $startPrice * exp($up)];
    }

    /**
     * Plain-language notes explaining the suggestion.
     *
     * @return list<string>
     */
    private function reasons(GridMode $mode, float $volatilityPct, bool $hasDailyAtr, float $dayRangePct, float $contraction, float $boxRatio, int $crossings, EntryTrigger $trigger, float $fairValue, float $tickSize, int $runtimeMinutes, float $netGridProfit, float $adverseFunding): array
    {
        $price = fn (float $value): string => rtrim(rtrim(number_format(round($value / $tickSize) * $tickSize, 8, '.', ''), '0'), '.');
        $window = $runtimeMinutes % 60 === 0 ? ($runtimeMinutes / 60).'h' : "{$runtimeMinutes} min";

        $reasons = [
            $hasDailyAtr
                ? sprintf('Highly volatile pair: daily ATR of %.1f%%, %.1f%% high-low range over the last 24h.', $volatilityPct, $dayRangePct)
                : sprintf('Highly volatile pair: %.1f%% high-low range over the last 24h (too new for a daily ATR).', $dayRangePct),
            sprintf('Stabilizing: the last hour moves %.0f%% as much as the busiest hour of the day.', $contraction * 100),
            sprintf('The last %s held a box of %.0f%% of the day\'s range, crossing its 5m EMA %d times.', $window, $boxRatio * 100, $crossings),
            match ($mode) {
                GridMode::Normal => 'No clear lean inside the box, so the grid is neutral.',
                GridMode::Long => 'The box is drifting up steadily, so the grid is skewed long and starts in the lower part of the box.',
                GridMode::Short => 'The box is drifting down steadily, so the grid is skewed short and starts in the upper part of the box.',
            },
            match ($trigger) {
                EntryTrigger::Immediate => sprintf('Price is at the box\'s fair value (%s), so the bot can start now.', $price($fairValue)),
                EntryTrigger::PriceBelow => sprintf('Price is above the start zone (fair value %s); wait for it to come back to the trigger.', $price($fairValue)),
                EntryTrigger::PriceAbove => sprintf('Price is below the start zone (fair value %s); wait for it to come back to the trigger.', $price($fairValue)),
            },
            sprintf('Planned for up to %s: close the bot when the time is up even if take profit was not hit.', $window),
        ];

        if ($netGridProfit < (float) config('grid.min_net_grid_profit')) {
            $reasons[] = sprintf('Thin profit per grid (%.2f%% after fees).', $netGridProfit * 100);
        }

        if ($adverseFunding > 0.001) {
            $reasons[] = 'Funding is high against this position.';
        }

        return $reasons;
    }

    /**
     * Trapezoid membership: 0 outside [$from, $to], 1 inside [$fullFrom, $fullTo], linear between.
     */
    private function band(float $value, float $from, float $fullFrom, float $fullTo, float $to): float
    {
        return match (true) {
            $value <= $from || $value >= $to => 0.0,
            $value < $fullFrom => ($value - $from) / ($fullFrom - $from),
            $value > $fullTo => ($to - $value) / ($to - $fullTo),
            default => 1.0,
        };
    }

    private function clamp(float $value, float $min = 0.0, float $max = 1.0): float
    {
        return max($min, min($max, $value));
    }
}
