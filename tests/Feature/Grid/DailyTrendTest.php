<?php

use App\Services\Grid\DailyTrend;

/**
 * @param  callable(int): float  $closeAt
 * @return list<array{close: float}>
 */
function dailyCandlesFrom(callable $closeAt, int $days = 90): array
{
    return array_map(fn (int $day): array => ['close' => $closeAt($day)], range(0, $days - 1));
}

function dailyDowntrend(): array
{
    return dailyCandlesFrom(fn (int $day): float => 100 * exp(-0.01 * $day));
}

function dailyUptrend(): array
{
    return dailyCandlesFrom(fn (int $day): float => 100 * exp(0.01 * $day));
}

function dailyFlat(): array
{
    return dailyCandlesFrom(fn (int $day): float => 100 + 2 * sin(2 * M_PI * $day / 7));
}

/**
 * A long rise that has just rolled over: the 20-day EMA is still above the 50-day EMA but falling.
 */
function dailyRollover(): array
{
    return dailyCandlesFrom(fn (int $day): float => $day < 80 ? 100 * exp(0.01 * $day) : 100 * exp(0.79) * exp(-0.035 * ($day - 79)));
}

it('classifies a falling market as a daily downtrend', function () {
    $trend = (new DailyTrend)->classify(dailyDowntrend());

    expect($trend['trend'])->toBe(DailyTrend::Bearish)
        ->and($trend['ema_fast_slope_pct'])->toBeLessThan(0)
        ->and($trend['close_vs_ema_fast_pct'])->toBeLessThan(0)
        ->and($trend['return_30d_pct'])->toEqualWithDelta((exp(-0.3) - 1) * 100, 0.01);
});

it('classifies a rising market as a daily uptrend', function () {
    expect((new DailyTrend)->classify(dailyUptrend())['trend'])->toBe(DailyTrend::Bullish);
});

it('classifies a sideways market, and a rollover the EMAs have not confirmed, as neutral', function () {
    $rollover = (new DailyTrend)->classify(dailyRollover());

    expect((new DailyTrend)->classify(dailyFlat())['trend'])->toBe(DailyTrend::Neutral)
        ->and($rollover['trend'])->toBe(DailyTrend::Neutral)
        ->and($rollover['ema_fast_slope_pct'])->toBeLessThan(-3);
});

it('needs the 20-day EMA to move at least the minimum slope before calling a daily trend', function () {
    $dip = dailyCandlesFrom(fn (int $day): float => 100 + 2 * sin($day / 3));
    $trend = (new DailyTrend)->classify($dip);

    expect($trend['ema_fast_slope_pct'])->toBeGreaterThan(-config('grid.daily_trend.min_trend_slope_pct'))
        ->and($trend['close_vs_ema_fast_pct'])->toBeLessThan(0)
        ->and($trend['trend'])->toBe(DailyTrend::Neutral);
});

it('returns null without enough daily history', function () {
    expect((new DailyTrend)->classify(array_slice(dailyDowntrend(), 0, config('grid.daily_trend.min_candles') - 1)))->toBeNull();
});
