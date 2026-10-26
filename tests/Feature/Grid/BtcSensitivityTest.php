<?php

use App\Services\Grid\BtcSensitivity;

/**
 * BTC hourly returns: small oscillation with a sharp move every 40 hours, alternating down and up.
 */
function btcReturnAt(int $hour): float
{
    if ($hour % 40 === 0) {
        return $hour % 80 === 0 ? -0.02 : 0.02;
    }

    return 0.002 * sin($hour * 1.3);
}

/**
 * Candles whose hourly returns come from the given function, starting at a fixed hour timestamp.
 *
 * @param  callable(int): float  $returnAt
 * @return list<array{open_time: int, close: float}>
 */
function candlesWithReturns(callable $returnAt, int $count = 480, int $firstHour = 0): array
{
    $candles = [];
    $close = 100.0;

    for ($hour = $firstHour; $hour < $firstHour + $count; $hour++) {
        $close *= exp($returnAt($hour));
        $candles[] = ['open_time' => 1_700_000_000_000 + $hour * 3_600_000, 'close' => $close];
    }

    return $candles;
}

it('measures a coin that moves twice as much as BTC', function () {
    $btc = candlesWithReturns(btcReturnAt(...));
    $coin = candlesWithReturns(fn (int $hour): float => 2 * btcReturnAt($hour));

    $sensitivity = (new BtcSensitivity)->measure($coin, $btc);

    expect($sensitivity['beta'])->toEqualWithDelta(2.0, 0.001)
        ->and($sensitivity['correlation'])->toEqualWithDelta(1.0, 0.001)
        ->and($sensitivity['shock_beta_down'])->toEqualWithDelta(2.0, 0.001)
        ->and($sensitivity['shock_beta_up'])->toEqualWithDelta(2.0, 0.001)
        ->and($sensitivity['shock_hours_down'])->toBeGreaterThanOrEqual(config('grid.min_shock_hours'))
        ->and($sensitivity['btc_shock_pct'])->toBeGreaterThan(0);
});

it('measures a stronger reaction to sudden BTC falls than to normal hours', function () {
    $btc = candlesWithReturns(btcReturnAt(...));
    $coin = candlesWithReturns(fn (int $hour): float => btcReturnAt($hour) * (btcReturnAt($hour) <= -0.02 ? 3 : 1));

    $sensitivity = (new BtcSensitivity)->measure($coin, $btc);

    expect($sensitivity['shock_beta_down'])->toEqualWithDelta(3.0, 0.001)
        ->and($sensitivity['shock_beta_up'])->toEqualWithDelta(1.0, 0.001)
        ->and($sensitivity['beta'])->toBeLessThan($sensitivity['shock_beta_down']);
});

it('falls back to the overall beta when there are too few shock hours', function () {
    config(['grid.min_shock_hours' => 100]);
    $btc = candlesWithReturns(btcReturnAt(...));
    $coin = candlesWithReturns(fn (int $hour): float => btcReturnAt($hour) * (btcReturnAt($hour) <= -0.02 ? 3 : 1));

    $sensitivity = (new BtcSensitivity)->measure($coin, $btc);

    expect($sensitivity['shock_beta_down'])->toBe($sensitivity['beta']);
});

it('compares only the hours both symbols have candles for', function () {
    $btc = candlesWithReturns(btcReturnAt(...));
    $listedLater = candlesWithReturns(fn (int $hour): float => 2 * btcReturnAt($hour), count: 300, firstHour: 180);
    $tooNew = candlesWithReturns(fn (int $hour): float => 2 * btcReturnAt($hour), count: 150, firstHour: 330);

    expect((new BtcSensitivity)->measure($listedLater, $btc)['beta'])->toEqualWithDelta(2.0, 0.001)
        ->and((new BtcSensitivity)->measure($tooNew, $btc))->toBeNull();
});
