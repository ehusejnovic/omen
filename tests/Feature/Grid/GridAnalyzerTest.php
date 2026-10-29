<?php

use App\Enums\EntryTrigger;
use App\Enums\GridGrade;
use App\Enums\GridMode;
use App\Services\Grid\GridAnalyzer;

/**
 * @param  callable(int): float  $closeAt
 * @return list<array{open: float, high: float, low: float, close: float}>
 */
function candlesFrom(callable $closeAt, int $count = 480): array
{
    $candles = [];
    $previous = $closeAt(0);

    for ($hour = 1; $hour <= $count; $hour++) {
        $close = $closeAt($hour);
        $candles[] = [
            'open' => $previous,
            'high' => max($previous, $close) * 1.002,
            'low' => min($previous, $close) * 0.998,
            'close' => $close,
        ];
        $previous = $close;
    }

    return $candles;
}

/**
 * @return array{last_price: float, quote_volume: float, price_change_pct: float, funding_rate: float, tick_size: float, min_notional: float}
 */
function marketAt(float $lastPrice): array
{
    return [
        'last_price' => $lastPrice,
        'quote_volume' => 800_000_000,
        'price_change_pct' => 1.2,
        'funding_rate' => 0.0001,
        'tick_size' => 0.001,
        'min_notional' => 5,
    ];
}

function rangingCandles(): array
{
    return candlesFrom(fn (int $hour): float => 100 + 4 * sin(2 * M_PI * $hour / 24) + 0.6 * sin($hour * 1.7));
}

it('suggests a normal grid around the price for a range-bound market', function () {
    $suggestion = (new GridAnalyzer)->analyze('RANGEUSDT', rangingCandles(), marketAt(100));

    expect($suggestion['mode'])->toBe(GridMode::Normal)
        ->and($suggestion['lower_price'])->toBeLessThan(100)
        ->and($suggestion['upper_price'])->toBeGreaterThan(100)
        ->and($suggestion['grid_count'])->toBeGreaterThanOrEqual(config('grid.min_grids'))
        ->and($suggestion['grid_count'])->toBeLessThanOrEqual(config('grid.max_grids'))
        ->and($suggestion['profit_per_grid_pct'])->toBeGreaterThan(0)
        ->and($suggestion['entry_trigger'])->toBe(EntryTrigger::Immediate)
        ->and($suggestion['entry_trigger_price'])->toBeNull()
        ->and($suggestion['take_profit_pnl_pct'])->toBeGreaterThan(0)
        ->and($suggestion['grade'])->toBe(GridGrade::fromScore($suggestion['score']));
});

it('keeps the stop loss within the configured share of margin and leverage within limits', function () {
    $suggestion = (new GridAnalyzer)->analyze('RANGEUSDT', rangingCandles(), marketAt(100));

    expect($suggestion['stop_loss_pnl_pct'])->toBeLessThan(0)
        ->and($suggestion['stop_loss_pnl_pct'])->toBeGreaterThanOrEqual(-config('grid.max_stop_loss') * 100)
        ->and($suggestion['leverage'])->toBeBetween(1, config('grid.max_leverage'));
});

it('waits for a pullback when a normal grid would start near the upper limit', function () {
    $analyzer = new GridAnalyzer;
    $centered = $analyzer->analyze('RANGEUSDT', rangingCandles(), marketAt(100));
    $nearTop = $centered['lower_price'] + 0.8 * ($centered['upper_price'] - $centered['lower_price']);

    $suggestion = $analyzer->analyze('RANGEUSDT', rangingCandles(), marketAt($nearTop));

    expect($suggestion['mode'])->toBe(GridMode::Normal)
        ->and($suggestion['entry_trigger'])->toBe(EntryTrigger::PriceBelow)
        ->and($suggestion['entry_trigger_price'])->toBeLessThan($nearTop);
});

it('suggests a long grid for a steady uptrend', function () {
    $candles = candlesFrom(fn (int $hour): float => 100 * exp(0.002 * $hour) + sin($hour / 3));

    $suggestion = (new GridAnalyzer)->analyze('UPUSDT', $candles, marketAt(end($candles)['close']));

    expect($suggestion['mode'])->toBe(GridMode::Long);
});

function healthyUptrendCandles(): array
{
    return candlesFrom(fn (int $hour): float => 100 * exp(0.0008 * $hour) + 2 * sin(2 * M_PI * $hour / 24) + 0.6 * sin($hour * 1.7));
}

it('suggests trailing up with a limit above the range for a healthy uptrend', function () {
    $candles = healthyUptrendCandles();
    $analyzer = new GridAnalyzer;
    $market = marketAt(end($candles)['close']);

    $trailing = $analyzer->analyze('UPUSDT', $candles, $market);
    $fixed = $analyzer->analyze('UPUSDT', $candles, $market, allowTrailing: false);

    expect($trailing['mode'])->toBe(GridMode::Long)
        ->and($trailing['trailing_up'])->toBeTrue()
        ->and($trailing['trailing_down'])->toBeFalse()
        ->and($trailing['trailing_limit_price'])->toBeGreaterThan($trailing['upper_price'])
        ->and($trailing['stop_loss_pnl_pct'])->toBeLessThan($fixed['stop_loss_pnl_pct'])
        ->and($trailing['stop_loss_pnl_pct'])->toBeGreaterThanOrEqual(-config('grid.max_stop_loss') * 100)
        ->and($trailing['take_profit_pnl_pct'])->toBeGreaterThan($fixed['take_profit_pnl_pct']);
});

it('suggests trailing down with a limit below the range for a healthy downtrend', function () {
    $candles = candlesFrom(fn (int $hour): float => 200 * exp(-0.0006 * $hour) + 2 * sin(2 * M_PI * $hour / 24) + 0.6 * sin($hour * 1.7));

    $suggestion = (new GridAnalyzer)->analyze('DOWNUSDT', $candles, marketAt(end($candles)['close']));

    expect($suggestion['mode'])->toBe(GridMode::Short)
        ->and($suggestion['trailing_down'])->toBeTrue()
        ->and($suggestion['trailing_up'])->toBeFalse()
        ->and($suggestion['trailing_limit_price'])->toBeLessThan($suggestion['lower_price']);
});

it('does not suggest trailing when trailing is disallowed', function () {
    $candles = healthyUptrendCandles();

    $suggestion = (new GridAnalyzer)->analyze('UPUSDT', $candles, marketAt(end($candles)['close']), allowTrailing: false);

    expect($suggestion['trailing_up'])->toBeFalse()
        ->and($suggestion['trailing_down'])->toBeFalse()
        ->and($suggestion['trailing_limit_price'])->toBeNull();
});

it('does not trail when the trailing worst case would exceed the stop loss budget at 1x', function () {
    $candles = healthyUptrendCandles();
    $market = marketAt(end($candles)['close']);
    $analyzer = new GridAnalyzer;
    $trailing = $analyzer->analyze('UPUSDT', $candles, $market);
    $fixed = $analyzer->analyze('UPUSDT', $candles, $market, allowTrailing: false);
    $fixedLossAtOneX = -$fixed['stop_loss_pnl_pct'] / 100 / $fixed['leverage'];
    $trailingScale = $trailing['trailing_limit_price'] / $trailing['upper_price'];
    config(['grid.max_stop_loss' => $fixedLossAtOneX * (1 + ($trailingScale - 1) / 2)]);

    $suggestion = $analyzer->analyze('UPUSDT', $candles, $market);

    expect($suggestion['mode'])->toBe(GridMode::Long)
        ->and($suggestion['trailing_up'])->toBeFalse()
        ->and($suggestion['trailing_limit_price'])->toBeNull();
});

it('does not trail an overextended trend or a range-bound market', function () {
    $overextended = candlesFrom(fn (int $hour): float => 100 * exp(0.002 * $hour) + sin($hour / 3));
    $analyzer = new GridAnalyzer;

    expect($analyzer->analyze('UPUSDT', $overextended, marketAt(end($overextended)['close']))['trailing_up'])->toBeFalse()
        ->and($analyzer->analyze('RANGEUSDT', rangingCandles(), marketAt(100))['trailing_up'])->toBeFalse()
        ->and($analyzer->analyze('RANGEUSDT', rangingCandles(), marketAt(100))['trailing_down'])->toBeFalse();
});

it('suggests a short grid for a steady downtrend', function () {
    $candles = candlesFrom(fn (int $hour): float => 100 * exp(-0.002 * $hour) + 0.3 * sin($hour / 3));

    $suggestion = (new GridAnalyzer)->analyze('DOWNUSDT', $candles, marketAt(end($candles)['close']));

    expect($suggestion['mode'])->toBe(GridMode::Short);
});

it('tightens the grid spacing with a lower ATR multiplier', function () {
    $analyzer = new GridAnalyzer;

    $default = $analyzer->analyze('RANGEUSDT', rangingCandles(), marketAt(100));
    $tight = $analyzer->analyze('RANGEUSDT', rangingCandles(), marketAt(100), 0.3);

    expect($tight['grid_spacing_pct'])->toBeLessThan($default['grid_spacing_pct'])
        ->and($tight['grid_count'])->toBeGreaterThan($default['grid_count']);
});

it('never spaces grids tighter than fees plus the minimum net profit', function () {
    $suggestion = (new GridAnalyzer)->analyze('RANGEUSDT', rangingCandles(), marketAt(100), 0.01);

    expect($suggestion['profit_per_grid_pct'])->toBeGreaterThanOrEqual(config('grid.min_net_grid_profit') * 100 - 0.01);
});

it('sizes a narrower range for a shorter range horizon', function () {
    $analyzer = new GridAnalyzer;
    $candles = candlesFrom(fn (int $hour): float => 100 + 4 * sin(2 * M_PI * $hour / 24) + 0.6 * sin($hour * 1.7) + 6 * sin(2 * M_PI * $hour / 240));

    $week = $analyzer->analyze('RANGEUSDT', $candles, marketAt(end($candles)['close']), rangeHorizonDays: 7);
    $threeDays = $analyzer->analyze('RANGEUSDT', $candles, marketAt(end($candles)['close']), rangeHorizonDays: 3);

    expect($threeDays['upper_price'] / $threeDays['lower_price'])->toBeLessThan($week['upper_price'] / $week['lower_price'])
        ->and($threeDays['metrics']['range_horizon_days'])->toBe(3)
        ->and($week['metrics']['range_horizon_days'])->toBe(7);
});

it('caps leverage for very volatile symbols', function () {
    $candles = candlesFrom(fn (int $hour): float => 100 + 30 * sin(2 * M_PI * $hour / 24));

    $suggestion = (new GridAnalyzer)->analyze('WILDUSDT', $candles, marketAt(100));

    expect($suggestion['metrics']['daily_volatility_pct'])->toBeGreaterThan(10)
        ->and($suggestion['leverage'])->toBeLessThanOrEqual(3);
});

it('returns null when a breakout would exceed the stop loss budget even at 1x', function () {
    config(['grid.max_stop_loss' => 0.01]);

    expect((new GridAnalyzer)->analyze('RANGEUSDT', rangingCandles(), marketAt(100)))->toBeNull();
});

/**
 * @return array{beta: float, correlation: float, shock_beta_down: float, shock_beta_up: float, shock_hours_down: int, shock_hours_up: int, btc_daily_volatility_pct: float, btc_shock_pct: float}
 */
function btcSensitivity(float $shockBeta, float $btcShockPct = 5): array
{
    return [
        'beta' => $shockBeta,
        'correlation' => 0.8,
        'shock_beta_down' => $shockBeta,
        'shock_beta_up' => $shockBeta,
        'shock_hours_down' => 10,
        'shock_hours_up' => 10,
        'btc_daily_volatility_pct' => $btcShockPct / 2,
        'btc_shock_pct' => $btcShockPct,
    ];
}

it('lowers leverage so a sudden BTC move stays clear of liquidation', function () {
    $analyzer = new GridAnalyzer;
    $calm = $analyzer->analyze('RANGEUSDT', rangingCandles(), marketAt(100), btcSensitivity: btcSensitivity(0.5));
    $jumpy = $analyzer->analyze('RANGEUSDT', rangingCandles(), marketAt(100), btcSensitivity: btcSensitivity(4, 10));

    expect($jumpy['leverage'])->toBeLessThan($calm['leverage'])
        ->and($jumpy['metrics']['btc_shock_loss_pct'])->toBeGreaterThanOrEqual(-config('grid.max_shock_loss') * 100)
        ->and($jumpy['metrics']['btc_shock_move_pct'])->toEqualWithDelta(-40.0, 0.01)
        ->and(collect($jumpy['reasons'])->contains(fn (string $reason): bool => str_contains($reason, 'sudden BTC move')))->toBeTrue()
        ->and($calm['metrics']['btc_beta'])->toBe(0.5);
});

it('returns null when a sudden BTC move would exceed the shock budget even at 1x', function () {
    config(['grid.max_shock_loss' => 0.01]);

    expect((new GridAnalyzer)->analyze('RANGEUSDT', rangingCandles(), marketAt(100), btcSensitivity: btcSensitivity(4, 10)))->toBeNull();
});

it('skips the BTC shock stress test without BTC sensitivity', function () {
    $suggestion = (new GridAnalyzer)->analyze('RANGEUSDT', rangingCandles(), marketAt(100));

    expect($suggestion['metrics']['btc_beta'])->toBeNull()
        ->and($suggestion['metrics']['btc_shock_loss_pct'])->toBeNull();
});

it('returns null when there is not enough history', function () {
    $candles = array_slice(rangingCandles(), 0, GridAnalyzer::MinimumCandles - 1);

    expect((new GridAnalyzer)->analyze('NEWUSDT', $candles, marketAt(100)))->toBeNull();
});
