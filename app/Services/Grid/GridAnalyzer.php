<?php

namespace App\Services\Grid;

use App\Enums\EntryTrigger;
use App\Enums\GridGrade;
use App\Enums\GridMode;
use Illuminate\Support\Arr;

/**
 * Turns one symbol's hourly candles into a suggested Binance futures grid bot configuration.
 *
 * All PnL figures are expressed as a share of the bot's margin, the way Binance reports
 * grid ROI, so they hold for any margin size.
 */
class GridAnalyzer
{
    public const int MinimumCandles = 200;

    private const int HoursPerWeek = 168;

    /**
     * Analyze a symbol, returning suggestion attributes or null when there is not enough data
     * or when a range breakout (or, with BTC sensitivity, a sudden BTC move) would lose more than
     * its budget even at 1x.
     *
     * @param  list<array{open: float, high: float, low: float, close: float}>  $candles  Closed 1h candles, oldest first.
     * @param  array{last_price: float, quote_volume: float, price_change_pct: float, funding_rate: float, tick_size: float, min_notional: float}  $market
     * @param  float|null  $spacingMultiplier  Grid spacing as a multiple of the hourly ATR; defaults to the configured value.
     * @param  bool  $allowTrailing  Whether trend-following bots may be suggested with trailing up or trailing down.
     * @param  array{beta: float, correlation: float, shock_beta_down: float, shock_beta_up: float, shock_hours_down: int, shock_hours_up: int, btc_daily_volatility_pct: float, btc_shock_pct: float}|null  $btcSensitivity  From {@see BtcSensitivity::measure()}; enables the BTC shock stress test.
     * @param  int|null  $rangeHorizonDays  Planned bot runtime in days; sizes the range, support/resistance lookback, take profit and trailing limit.
     * @param  array{trend: string, ema_fast: float, ema_slow: float, ema_fast_slope_pct: float, close_vs_ema_fast_pct: float, return_30d_pct: float|null}|null  $dailyTrend  From {@see DailyTrend::classify()}; grids never run against a bearish or bullish daily trend.
     * @return array<string, mixed>|null
     */
    public function analyze(string $symbol, array $candles, array $market, ?float $spacingMultiplier = null, bool $allowTrailing = true, ?array $btcSensitivity = null, ?int $rangeHorizonDays = null, ?array $dailyTrend = null): ?array
    {
        if (count($candles) < self::MinimumCandles || $market['last_price'] <= 0) {
            return null;
        }

        $fee = (float) config('grid.maker_fee');
        $runtimeDays = $rangeHorizonDays ?? (int) config('grid.range_horizon_days');
        $price = $market['last_price'];

        $closes = array_column($candles, 'close');
        $highs = array_column($candles, 'high');
        $lows = array_column($candles, 'low');
        $weekCloses = array_slice($closes, -self::HoursPerWeek);
        $weekReturns = Indicators::logReturns(array_slice($closes, -self::HoursPerWeek - 1));

        $dailyVolatility = Indicators::standardDeviation($weekReturns) * sqrt(24);

        if ($dailyVolatility <= 0) {
            return null;
        }

        $runtimeVolatility = min($dailyVolatility * sqrt($runtimeDays), 0.35);
        $atr = Indicators::atr($highs, $lows, $closes);
        $atrPct = $atr / $price;

        $fourHour = $this->aggregate($candles, 4);
        $adx = Indicators::adx($fourHour['highs'], $fourHour['lows'], $fourHour['closes']);
        $efficiency = Indicators::efficiencyRatio(array_slice($fourHour['closes'], -42));
        $fastTrend = Arr::last(Indicators::ema($fourHour['closes'], 20));
        $slowTrend = Arr::last(Indicators::ema($fourHour['closes'], 50));
        $hourlyAverage = Indicators::ema($closes, 20);
        $hourlyMean = Arr::last($hourlyAverage);
        $rsi = Indicators::rsi($closes);

        $driftPerDay = Indicators::slope(array_map('log', $weekCloses)) * 24;
        $trendSignal = $driftPerDay / $dailyVolatility;
        $crossingsPerDay = Indicators::crossings($weekCloses, array_slice($hourlyAverage, -self::HoursPerWeek)) / 7;
        $pathPerDay = array_sum(array_map('abs', $weekReturns)) / 7;

        $mode = $this->mode($adx, $efficiency, $trendSignal, $fastTrend, $slowTrend);

        // Never run a grid against the daily trend: follow it when the 4h trend agrees, and skip
        // the coin while the 4h chart is still bouncing against it.
        $dailyDirection = $dailyTrend['trend'] ?? DailyTrend::Neutral;

        if ($dailyDirection === DailyTrend::Bearish) {
            if ($fastTrend >= $slowTrend) {
                return null;
            }

            $mode = GridMode::Short;
        } elseif ($dailyDirection === DailyTrend::Bullish) {
            if ($fastTrend <= $slowTrend) {
                return null;
            }

            $mode = GridMode::Long;
        }

        $zoneHours = $runtimeDays * 24;
        $support = Indicators::percentile(array_slice($lows, -$zoneHours), 5);
        $resistance = Indicators::percentile(array_slice($highs, -$zoneHours), 95);
        [$lower, $upper] = $this->range($mode, $price, $runtimeVolatility, $support, $resistance, $driftPerDay * $runtimeDays);

        $tickSize = $market['tick_size'] > 0 ? $market['tick_size'] : $price / 1e6;
        $lower = max($tickSize, floor($lower / $tickSize) * $tickSize);
        $upper = ceil($upper / $tickSize) * $tickSize;

        $geometric = $upper / $lower > 1.25;
        $spacingMultiplier ??= (float) config('grid.spacing_multiplier');
        $targetSpacing = min(max($atrPct * $spacingMultiplier, 2 * $fee + (float) config('grid.min_net_grid_profit')), 0.03);
        $gridCount = (int) round($geometric
            ? log($upper / $lower) / log(1 + $targetSpacing)
            : ($upper - $lower) / ($price * $targetSpacing));
        $gridCount = max((int) config('grid.min_grids'), min((int) config('grid.max_grids'), $gridCount));

        $levels = $this->levels($lower, $upper, $gridCount, $geometric);
        $spacing = $geometric ? ($upper / $lower) ** (1 / $gridCount) - 1 : ($upper - $lower) / $gridCount / $price;
        $netGridProfit = $spacing - 2 * $fee;

        $breakoutBuffer = 1.5 * $atr;
        $breakoutDown = max($tickSize, $lower - $breakoutBuffer);
        $breakoutUp = $upper + $breakoutBuffer;
        $qtyPerGrid = 1 / ($gridCount * $price);
        $lossAtOneX = match ($mode) {
            GridMode::Long => -$this->unrealizedPnl($mode, $levels, $price, $breakoutDown, $qtyPerGrid),
            GridMode::Short => -$this->unrealizedPnl($mode, $levels, $price, $breakoutUp, $qtyPerGrid),
            GridMode::Normal => max(
                -$this->unrealizedPnl($mode, $levels, $price, $breakoutDown, $qtyPerGrid),
                -$this->unrealizedPnl($mode, $levels, $price, $breakoutUp, $qtyPerGrid),
            ),
        };
        $lossAtOneX = max($lossAtOneX, 0.001);

        if ($lossAtOneX > (float) config('grid.max_stop_loss')) {
            return null;
        }

        $trailingLimit = null;
        $trailingReach = 1.0;

        if ($allowTrailing && $this->shouldTrail($mode, $adx, $trendSignal, $rsi)) {
            $limit = $mode === GridMode::Long
                ? ceil($upper * (1 + $runtimeVolatility) / $tickSize) * $tickSize
                : max($tickSize, floor($lower * (1 - $runtimeVolatility) / $tickSize) * $tickSize);

            // Worst case: the grid trails all the way to the limit, then the price reverses into the
            // shifted stop zone. The position is the same size but bought further along, so the loss
            // at 1x grows with the distance trailed. Profit banked while trailing is ignored.
            $trailingLossAtOneX = $lossAtOneX * ($mode === GridMode::Long ? $limit / $upper : $lower / $limit);

            // Only trail when that worst case still fits the stop loss budget at 1x.
            if ($trailingLossAtOneX <= (float) config('grid.max_stop_loss')) {
                $trailingLimit = $limit;
                $lossAtOneX = $trailingLossAtOneX;
                $trailingReach = abs($limit - $price) / abs(($mode === GridMode::Long ? $upper : $lower) - $price);
            }
        }

        $trailing = $trailingLimit !== null;

        $leverageCap = match (true) {
            $dailyVolatility >= 0.10 => 3,
            $dailyVolatility >= 0.06 => 5,
            default => (int) config('grid.max_leverage'),
        };
        $leverage = max(1, min($leverageCap, (int) floor((float) config('grid.max_stop_loss') / $lossAtOneX)));

        // BTC shock stress test: a sudden BTC move can gap the price straight through the stop zone,
        // so the loss at the expected shock price must stay clear of liquidation.
        $shockMove = null;
        $shockLossAtOneX = null;
        $leverageBeforeShock = $leverage;

        if ($btcSensitivity !== null) {
            $btcShock = $btcSensitivity['btc_shock_pct'] / 100;
            $shockMoves = match ($mode) {
                GridMode::Long => [-$btcSensitivity['shock_beta_down'] * $btcShock],
                GridMode::Short => [$btcSensitivity['shock_beta_up'] * $btcShock],
                GridMode::Normal => [-$btcSensitivity['shock_beta_down'] * $btcShock, $btcSensitivity['shock_beta_up'] * $btcShock],
            };

            foreach ($shockMoves as $move) {
                $loss = -$this->unrealizedPnl($mode, $levels, $price, $price * (1 + max($move, -0.9)), $qtyPerGrid);

                if ($shockLossAtOneX === null || $loss > $shockLossAtOneX) {
                    $shockLossAtOneX = $loss;
                    $shockMove = $move;
                }
            }

            $shockLossAtOneX = max($shockLossAtOneX, 0.0);
            $maxShockLeverage = $shockLossAtOneX > 0
                ? (int) floor((float) config('grid.max_shock_loss') / $shockLossAtOneX)
                : $leverage;

            if ($maxShockLeverage < 1) {
                return null;
            }

            $leverage = min($leverage, $maxShockLeverage);
        }

        $stopLoss = $lossAtOneX * $leverage;

        $roundTripsPerDay = $pathPerDay / $spacing / 2 * 0.75;
        $exposure = match ($mode) {
            GridMode::Long => 0.5,
            GridMode::Short => -0.5,
            GridMode::Normal => 0.0,
        };
        $dailyRoi = $roundTripsPerDay * ($leverage / $gridCount) * $netGridProfit
            - 3 * $market['funding_rate'] * $leverage * $exposure;
        $trendProfit = match ($mode) {
            GridMode::Long => $this->unrealizedPnl($mode, $levels, $price, $upper, $qtyPerGrid) * $leverage,
            GridMode::Short => $this->unrealizedPnl($mode, $levels, $price, $lower, $qtyPerGrid) * $leverage,
            GridMode::Normal => 0.0,
        };
        $takeProfit = min(max($dailyRoi * $runtimeDays + 0.5 * $trendProfit * $trailingReach, 0.03), 1.5);

        [$trigger, $triggerPrice] = $this->entryTrigger($mode, $price, $lower, $upper, $hourlyMean, $atr, $rsi);

        if ($triggerPrice !== null) {
            $triggerPrice = round($triggerPrice / $tickSize) * $tickSize;
        }

        $breakdown = [
            'structure' => $this->structureScore($mode, $efficiency, $crossingsPerDay, $adx, $trendSignal, $rsi),
            'volatility' => 20 * $this->band($dailyVolatility, 0.012, 0.025, 0.06, 0.14),
            'profitability' => 20 * $this->clamp($dailyRoi / 0.01),
            'liquidity' => 15 * $this->clamp((log10(max($market['quote_volume'], 1)) - log10(2e7)) / (log10(1e9) - log10(2e7))),
            'funding' => 10 * $this->clamp(1 - max(0, $this->adverseFunding($mode, $market['funding_rate'])) / 0.0005),
            'risk_reward' => 5 * $this->clamp(($takeProfit / $stopLoss) / 0.75),
            'penalty' => abs($market['price_change_pct']) > 20 ? -10 : 0,
            'daily_trend' => 0.0 - $this->dailyTiltPenalty($mode, $dailyTrend),
        ];
        $score = (int) round(max(0, min(100, array_sum($breakdown))));
        $grade = GridGrade::fromScore($score);
        $allocation = round($grade->marginAllocationPct() * $this->clamp(0.04 / $dailyVolatility, 0.5, 1) * 2) / 2;

        return [
            'symbol' => $symbol,
            'mode' => $mode,
            'grade' => $grade,
            'score' => $score,
            'last_price' => $price,
            'lower_price' => $lower,
            'upper_price' => $upper,
            'grid_count' => $gridCount,
            'grid_type' => $geometric ? 'geometric' : 'arithmetic',
            'grid_spacing_pct' => round($spacing * 100, 4),
            'profit_per_grid_pct' => round($netGridProfit * 100, 4),
            'leverage' => $leverage,
            'margin_allocation_pct' => $allocation,
            'trailing_up' => $trailing && $mode === GridMode::Long,
            'trailing_down' => $trailing && $mode === GridMode::Short,
            'trailing_limit_price' => $trailingLimit,
            'entry_trigger' => $trigger,
            'entry_trigger_price' => $triggerPrice,
            'take_profit_pnl_pct' => round($takeProfit * 100, 2),
            'stop_loss_pnl_pct' => round(-$stopLoss * 100, 2),
            'estimated_daily_roi_pct' => round($dailyRoi * 100, 3),
            'quote_volume_24h' => round($market['quote_volume'], 2),
            'price_change_24h_pct' => round($market['price_change_pct'], 2),
            'funding_rate_pct' => round($market['funding_rate'] * 100, 5),
            'metrics' => [
                'daily_volatility_pct' => round($dailyVolatility * 100, 2),
                'atr_pct' => round($atrPct * 100, 3),
                'adx_4h' => round($adx, 1),
                'efficiency_ratio' => round($efficiency, 3),
                'rsi_1h' => round($rsi, 1),
                'trend_signal' => round($trendSignal, 2),
                'drift_per_day_pct' => round($driftPerDay * 100, 2),
                'crossings_per_day' => round($crossingsPerDay, 1),
                'range_horizon_days' => $runtimeDays,
                'support' => $support,
                'resistance' => $resistance,
                'ema20_4h' => $fastTrend,
                'ema50_4h' => $slowTrend,
                'breakout_down_price' => $breakoutDown,
                'breakout_up_price' => $breakoutUp,
                'tick_size' => $tickSize,
                'min_notional' => $market['min_notional'],
                'btc_beta' => $btcSensitivity['beta'] ?? null,
                'btc_correlation' => $btcSensitivity['correlation'] ?? null,
                'btc_shock_beta_down' => $btcSensitivity['shock_beta_down'] ?? null,
                'btc_shock_beta_up' => $btcSensitivity['shock_beta_up'] ?? null,
                'btc_shock_hours_down' => $btcSensitivity['shock_hours_down'] ?? null,
                'btc_shock_hours_up' => $btcSensitivity['shock_hours_up'] ?? null,
                'btc_shock_pct' => $btcSensitivity['btc_shock_pct'] ?? null,
                'btc_shock_move_pct' => $shockMove === null ? null : round($shockMove * 100, 2),
                'btc_shock_loss_pct' => $shockLossAtOneX === null ? null : round(-$shockLossAtOneX * $leverage * 100, 2),
                'daily_trend' => $dailyTrend['trend'] ?? null,
                'daily_ema20_slope_pct' => $dailyTrend['ema_fast_slope_pct'] ?? null,
                'daily_close_vs_ema20_pct' => $dailyTrend['close_vs_ema_fast_pct'] ?? null,
                'daily_return_30d_pct' => $dailyTrend['return_30d_pct'] ?? null,
                'score_breakdown' => array_map(fn (float|int $points): float => round($points, 1), $breakdown),
            ],
            'reasons' => [
                ...$this->reasons($mode, $dailyVolatility, $adx, $efficiency, $crossingsPerDay, $trendSignal, $rsi, $market['funding_rate'], $trigger, $netGridProfit, $leverage, $leverageCap),
                ...($trailing ? [sprintf(
                    'Strong, healthy trend: trailing %s lets the grid follow a breakout instead of going idle, up to the trailing limit.',
                    $mode === GridMode::Long ? 'up' : 'down',
                )] : []),
                ...$this->dailyTrendReasons($mode, $dailyTrend, $breakdown['daily_trend']),
                ...($leverage < $leverageBeforeShock ? [sprintf(
                    'Leverage lowered from %dx to %dx so a sudden BTC move of %+.1f%% (expected %+.1f%% here) stays clear of liquidation.',
                    $leverageBeforeShock,
                    $leverage,
                    ($shockMove > 0 ? 1 : -1) * $btcSensitivity['btc_shock_pct'],
                    $shockMove * 100,
                )] : []),
            ],
        ];
    }

    /**
     * Points lost when the daily 20-day EMA slopes against the grid without forming a full daily trend.
     * Normal bots lose out to a tilt either way; Long and Short bots only to a tilt against them.
     *
     * @param  array{ema_fast_slope_pct: float}|null  $dailyTrend
     */
    private function dailyTiltPenalty(GridMode $mode, ?array $dailyTrend): float
    {
        if ($dailyTrend === null) {
            return 0.0;
        }

        $slope = $dailyTrend['ema_fast_slope_pct'];
        $against = match ($mode) {
            GridMode::Normal => abs($slope),
            GridMode::Long => max(0.0, -$slope),
            GridMode::Short => max(0.0, $slope),
        };
        $from = (float) config('grid.daily_trend.penalty_from_slope_pct');
        $full = (float) config('grid.daily_trend.penalty_full_slope_pct');

        return (float) config('grid.daily_trend.penalty_points') * $this->clamp(($against - $from) / ($full - $from));
    }

    /**
     * Explain how the daily trend shaped the suggestion.
     *
     * @param  array{trend: string, ema_fast_slope_pct: float}|null  $dailyTrend
     * @return list<string>
     */
    private function dailyTrendReasons(GridMode $mode, ?array $dailyTrend, float $penalty): array
    {
        if ($dailyTrend === null) {
            return ['Not enough daily history to judge the daily trend.'];
        }

        $reasons = match ($dailyTrend['trend']) {
            DailyTrend::Bearish => ['Daily downtrend (price below a falling 20-day EMA, which is below the 50-day EMA) and the 4h trend agrees, so this is a Short bot rather than a Normal one.'],
            DailyTrend::Bullish => ['Daily uptrend (price above a rising 20-day EMA, which is above the 50-day EMA) and the 4h trend agrees, so this is a Long bot rather than a Normal one.'],
            default => [],
        };

        if ($penalty < 0) {
            $reasons[] = sprintf(
                'Daily 20-day EMA is tilting %s (%+.1f%% over %d days) against this %s grid: %.0f points.',
                $dailyTrend['ema_fast_slope_pct'] < 0 ? 'down' : 'up',
                $dailyTrend['ema_fast_slope_pct'],
                (int) config('grid.daily_trend.slope_days'),
                $mode->value,
                $penalty,
            );
        }

        return $reasons;
    }

    /**
     * Pick the grid direction from trend strength and alignment.
     */
    private function mode(float $adx, float $efficiency, float $trendSignal, float $fastTrend, float $slowTrend): GridMode
    {
        $trending = ($adx >= 25 && $efficiency >= 0.3) || abs($trendSignal) >= 0.4;

        return match (true) {
            $trending && $trendSignal > 0 && $fastTrend > $slowTrend => GridMode::Long,
            $trending && $trendSignal < 0 && $fastTrend < $slowTrend => GridMode::Short,
            default => GridMode::Normal,
        };
    }

    /**
     * Price range sized to the expected move over the bot's runtime, skewed with the trend.
     *
     * @return array{0: float, 1: float}
     */
    private function range(GridMode $mode, float $price, float $volatility, float $support, float $resistance, float $expectedDrift): array
    {
        $below = fn (float $multiple): float => $price * (1 - min($multiple * $volatility, 0.6));
        $above = fn (float $multiple): float => $price * (1 + min($multiple * $volatility, 1.5));

        return match ($mode) {
            GridMode::Normal => $this->centeredRange($price, $volatility, $support, $resistance),
            GridMode::Long => [
                max(min($support, $below(0.8)), $below(1.5)),
                $above($this->clamp(1.2 + max(0, $expectedDrift) * 0.5 / $volatility, 1.2, 2.0)),
            ],
            GridMode::Short => [
                $below($this->clamp(1.2 + max(0, -$expectedDrift) * 0.5 / $volatility, 1.2, 2.0)),
                min(max($resistance, $above(0.8)), $above(1.5)),
            ],
        };
    }

    /**
     * Range centered on the recent trading zone rather than the current price, widened to contain the price.
     *
     * @return array{0: float, 1: float}
     */
    private function centeredRange(float $price, float $volatility, float $support, float $resistance): array
    {
        $middle = ($support + $resistance) / 2;
        $halfWidth = $this->clamp(($resistance - $support) / 2, $volatility * $middle, min(2.5 * $volatility, 0.6) * $middle);
        $lower = min($middle - $halfWidth, $price - 0.25 * $halfWidth);
        $upper = max($middle + $halfWidth, $price + 0.25 * $halfWidth);

        return [$lower, $upper];
    }

    /**
     * Grid line prices from the lower to the upper limit.
     *
     * @return list<float>
     */
    private function levels(float $lower, float $upper, int $gridCount, bool $geometric): array
    {
        $levels = [];

        for ($i = 0; $i <= $gridCount; $i++) {
            $levels[] = $geometric
                ? $lower * ($upper / $lower) ** ($i / $gridCount)
                : $lower + ($upper - $lower) * $i / $gridCount;
        }

        return $levels;
    }

    /**
     * Estimated PnL in margin units at 1x when price travels from the start price to the exit price.
     *
     * Realized grid profit is ignored so the estimate stays conservative.
     *
     * @param  list<float>  $levels
     */
    private function unrealizedPnl(GridMode $mode, array $levels, float $startPrice, float $exitPrice, float $qtyPerGrid): float
    {
        $levelsAbove = array_filter($levels, fn (float $level): bool => $level > $startPrice);
        $levelsBelow = array_filter($levels, fn (float $level): bool => $level < $startPrice);
        $pnl = match ($mode) {
            GridMode::Long => count($levelsAbove) * $qtyPerGrid * ($exitPrice - $startPrice),
            GridMode::Short => count($levelsBelow) * $qtyPerGrid * ($startPrice - $exitPrice),
            GridMode::Normal => 0.0,
        };

        if ($exitPrice < $startPrice) {
            foreach ($levelsBelow as $level) {
                if ($level >= $exitPrice) {
                    $pnl += $qtyPerGrid * ($exitPrice - $level);
                }
            }
        } else {
            foreach ($levelsAbove as $level) {
                if ($level <= $exitPrice) {
                    $pnl += $qtyPerGrid * ($level - $exitPrice);
                }
            }
        }

        return $pnl;
    }

    /**
     * Trail only with a strong but not exhausted trend, and never against the bot's direction.
     */
    private function shouldTrail(GridMode $mode, float $adx, float $trendSignal, float $rsi): bool
    {
        $healthyTrend = $adx >= 25 && $adx <= 40;

        return match ($mode) {
            GridMode::Long => $healthyTrend && $trendSignal >= 0.6 && $rsi <= 70,
            GridMode::Short => $healthyTrend && $trendSignal <= -0.6 && $rsi >= 30,
            GridMode::Normal => false,
        };
    }

    /**
     * Start immediately unless price is stretched away from where the grid wants to begin.
     *
     * @return array{0: EntryTrigger, 1: float|null}
     */
    private function entryTrigger(GridMode $mode, float $price, float $lower, float $upper, float $hourlyMean, float $atr, float $rsi): array
    {
        $width = $upper - $lower;
        $position = ($price - $lower) / $width;

        return match (true) {
            $mode === GridMode::Normal && $position > 0.65 => [EntryTrigger::PriceBelow, $lower + 0.6 * $width],
            $mode === GridMode::Normal && $position < 0.35 => [EntryTrigger::PriceAbove, $lower + 0.4 * $width],
            $mode === GridMode::Long && ($rsi > 65 || $price > $hourlyMean + $atr) => [EntryTrigger::PriceBelow, max($hourlyMean, $price - $atr)],
            $mode === GridMode::Short && ($rsi < 35 || $price < $hourlyMean - $atr) => [EntryTrigger::PriceAbove, min($hourlyMean, $price + $atr)],
            default => [EntryTrigger::Immediate, null],
        };
    }

    /**
     * Up to 30 points for how well the price structure suits the chosen grid mode.
     */
    private function structureScore(GridMode $mode, float $efficiency, float $crossingsPerDay, float $adx, float $trendSignal, float $rsi): float
    {
        if ($mode === GridMode::Normal) {
            return 30 * (
                0.45 * $this->clamp((0.5 - $efficiency) / 0.4)
                + 0.35 * $this->clamp($crossingsPerDay / 4)
                + 0.2 * (1 - $this->clamp(($adx - 20) / 20))
            );
        }

        $momentumRoom = $mode === GridMode::Long
            ? $this->clamp((80 - $rsi) / 10)
            : $this->clamp(($rsi - 20) / 10);

        return 30 * (
            0.4 * $this->band($adx, 20, 25, 40, 55)
            + 0.4 * $this->band(abs($trendSignal), 0.25, 0.4, 1.0, 1.6)
            + 0.2 * $momentumRoom
        );
    }

    /**
     * Funding rate that the bot's typical exposure pays, per 8h.
     */
    private function adverseFunding(GridMode $mode, float $fundingRate): float
    {
        return match ($mode) {
            GridMode::Long => $fundingRate,
            GridMode::Short => -$fundingRate,
            GridMode::Normal => abs($fundingRate) / 2,
        };
    }

    /**
     * Plain-language notes explaining the suggestion.
     *
     * @return list<string>
     */
    private function reasons(GridMode $mode, float $dailyVolatility, float $adx, float $efficiency, float $crossingsPerDay, float $trendSignal, float $rsi, float $fundingRate, EntryTrigger $trigger, float $netGridProfit, int $leverage, int $leverageCap): array
    {
        $reasons = [match ($mode) {
            GridMode::Normal => sprintf('Range-bound: efficiency ratio %.2f, %.1f mean crossings per day, ADX %.0f.', $efficiency, $crossingsPerDay, $adx),
            GridMode::Long => sprintf('Uptrend: ADX %.0f with a drift of %.2fσ per day, so the grid is skewed long.', $adx, $trendSignal),
            GridMode::Short => sprintf('Downtrend: ADX %.0f with a drift of %.2fσ per day, so the grid is skewed short.', $adx, $trendSignal),
        }];

        $reasons[] = match (true) {
            $dailyVolatility < 0.012 => sprintf('Low volatility (%.1f%% per day) limits how often grids fill.', $dailyVolatility * 100),
            $dailyVolatility > 0.08 => sprintf('High volatility (%.1f%% per day): wide range and reduced leverage.', $dailyVolatility * 100),
            default => sprintf('Volatility of %.1f%% per day keeps grids filling.', $dailyVolatility * 100),
        };

        if ($netGridProfit < (float) config('grid.min_net_grid_profit')) {
            $reasons[] = sprintf('Thin profit per grid (%.2f%% after fees).', $netGridProfit * 100);
        }

        $adverseFunding = $this->adverseFunding($mode, $fundingRate);

        if ($adverseFunding > 0.0003) {
            $reasons[] = sprintf('Funding of %+.4f%% per 8h works against this position.', $fundingRate * 100);
        }

        if ($leverage === $leverageCap && $leverageCap < (int) config('grid.max_leverage')) {
            $reasons[] = sprintf('Leverage capped at %dx because of volatility.', $leverageCap);
        }

        $reasons[] = match ($trigger) {
            EntryTrigger::Immediate => 'Price sits in a good starting zone, so the bot can start now.',
            EntryTrigger::PriceBelow => sprintf('Price is stretched (RSI %.0f); wait for a pullback to the trigger.', $rsi),
            EntryTrigger::PriceAbove => sprintf('Price is stretched (RSI %.0f); wait for a bounce to the trigger.', $rsi),
        };

        return $reasons;
    }

    /**
     * Combine candles into higher-timeframe series, dropping any leading partial group.
     *
     * @param  list<array{open: float, high: float, low: float, close: float}>  $candles
     * @param  positive-int  $size
     * @return array{highs: list<float>, lows: list<float>, closes: list<float>}
     */
    private function aggregate(array $candles, int $size): array
    {
        $series = ['highs' => [], 'lows' => [], 'closes' => []];

        foreach (array_chunk(array_slice($candles, count($candles) % $size), $size) as $group) {
            $series['highs'][] = max(array_column($group, 'high'));
            $series['lows'][] = min(array_column($group, 'low'));
            $series['closes'][] = end($group)['close'];
        }

        return $series;
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
