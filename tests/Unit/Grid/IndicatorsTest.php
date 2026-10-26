<?php

use App\Services\Grid\Indicators;

it('measures a straight line as fully efficient and a round trip as pure chop', function () {
    expect(Indicators::efficiencyRatio([1, 2, 3, 4, 5]))->toBe(1.0)
        ->and(Indicators::efficiencyRatio([1, 3, 1, 3, 1]))->toBe(0.0);
});

it('returns RSI extremes for one-directional moves', function () {
    $rising = range(1, 40);

    expect(Indicators::rsi(array_map('floatval', $rising)))->toBe(100.0)
        ->and(Indicators::rsi(array_map('floatval', array_reverse($rising))))->toBe(0.0);
});

it('counts each time closes cross the average', function () {
    expect(Indicators::crossings([1, 3, 1, 3, 3], [2, 2, 2, 2, 2]))->toBe(3)
        ->and(Indicators::crossings([1, 2, 1], [2, 2, 2]))->toBe(0);
});

it('interpolates percentiles between sorted values', function () {
    expect(Indicators::percentile([10, 0, 20], 25))->toBe(5.0)
        ->and(Indicators::percentile([10, 0, 20], 100))->toBe(20.0);
});

it('reports the per-bar slope of a linear series', function () {
    expect(Indicators::slope([2, 4, 6, 8]))->toBe(2.0);
});

it('reports a high ADX for a steady trend and a low ADX for oscillation', function () {
    $trendCloses = array_map(fn (int $i): float => 100 + $i, range(0, 99));
    $chopCloses = array_map(fn (int $i): float => 100 + ($i % 2 === 0 ? 1 : -1), range(0, 99));
    $highs = fn (array $closes): array => array_map(fn (float $close): float => $close + 0.5, $closes);
    $lows = fn (array $closes): array => array_map(fn (float $close): float => $close - 0.5, $closes);

    expect(Indicators::adx($highs($trendCloses), $lows($trendCloses), $trendCloses))->toBeGreaterThan(50)
        ->and(Indicators::adx($highs($chopCloses), $lows($chopCloses), $chopCloses))->toBeLessThan(20);
});

it('measures beta and correlation of a series that moves twice as much', function () {
    $x = [0.01, -0.02, 0.015, -0.005, 0.03];
    $y = array_map(fn (float $value): float => 2 * $value, $x);

    expect(Indicators::beta($x, $y))->toEqualWithDelta(2.0, 1e-9)
        ->and(Indicators::correlation($x, $y))->toEqualWithDelta(1.0, 1e-9)
        ->and(Indicators::correlation($x, array_map(fn (float $value): float => -$value, $x)))->toEqualWithDelta(-1.0, 1e-9)
        ->and(Indicators::betaThroughOrigin([-0.02, -0.03], [-0.06, -0.09]))->toEqualWithDelta(3.0, 1e-9);
});
