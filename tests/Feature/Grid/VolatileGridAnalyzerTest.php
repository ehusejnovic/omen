<?php

use App\Enums\EntryTrigger;
use App\Enums\GridGrade;
use App\Enums\GridMode;
use App\Services\Grid\VolatileGridAnalyzer;

/**
 * Closed 5m candles: a quiet day around 100, an impulse to `100 + $impulse`, then `$tail` of the
 * given shape over the last 50 candles (about four hours).
 *
 * @param  'box'|'drift'|'grind'|'impulse'  $tail
 * @return list<array{open: float, high: float, low: float, close: float}>
 */
function volatileCandles(float $impulse = 30, string $tail = 'box', int $count = 300): array
{
    $settled = 100 + $impulse * 0.93;
    $closeAt = fn (int $i): float => match (true) {
        $tail === 'grind' => 100 + $impulse * $i / $count + 0.4 * sin($i * 1.3),
        $i < 200 => 100 + 0.3 * sin($i / 3),
        $i < 250 => 100 + $impulse * ($i - 200) / 50 + 0.06 * $impulse * sin($i * 1.1),
        $tail === 'impulse' && $i >= $count - 20 => $settled - $impulse * 0.5 * ($i - $count + 20) / 20,
        $tail === 'drift' && $i >= $count - 30 => $settled - 3 + 0.2 * ($i - $count + 30) + 0.3 * sin($i * 2.1),
        default => $settled + 0.012 * $settled * sin(2 * M_PI * $i / 8),
    };
    $spreadAt = fn (int $i): float => $i >= 200 && $i < 250 && $tail !== 'grind' ? 0.012 : 0.002;

    $candles = [];
    $previous = $closeAt(0);

    for ($i = 1; $i <= $count; $i++) {
        $close = $closeAt($i);
        $candles[] = [
            'open' => $previous,
            'high' => max($previous, $close) * (1 + $spreadAt($i)),
            'low' => min($previous, $close) * (1 - $spreadAt($i)),
            'close' => $close,
        ];
        $previous = $close;
    }

    return $candles;
}

/**
 * @return array{last_price: float, quote_volume: float, price_change_pct: float, funding_rate: float, tick_size: float, min_notional: float}
 */
function volatileMarket(float $lastPrice): array
{
    return [
        'last_price' => $lastPrice,
        'quote_volume' => 300_000_000,
        'price_change_pct' => 25,
        'funding_rate' => 0.0001,
        'tick_size' => 0.001,
        'min_notional' => 5,
    ];
}

/**
 * @param  list<array{close: float}>  $candles
 */
function lastClose(array $candles): float
{
    return end($candles)['close'];
}

it('suggests a neutral short bot that starts now when a volatile pair settles at its fair value', function () {
    $candles = volatileCandles();
    $suggestion = (new VolatileGridAnalyzer)->analyze('PUMPUSDT', $candles, volatileMarket(lastClose($candles)), 14.0);
    $price = lastClose($candles);

    expect($suggestion['mode'])->toBe(GridMode::Normal)
        ->and($suggestion['entry_trigger'])->toBe(EntryTrigger::Immediate)
        ->and($suggestion['entry_trigger_price'])->toBeNull()
        ->and($suggestion['grid_type'])->toBe('geometric')
        ->and($suggestion['lower_price'])->toBeLessThan($suggestion['metrics']['box_low'])
        ->and($suggestion['upper_price'])->toBeGreaterThan($suggestion['metrics']['box_high'])
        ->and($suggestion['lower_price'])->toBeLessThan($price)
        ->and($suggestion['upper_price'])->toBeGreaterThan($price)
        ->and($suggestion['grid_count'])->toBeBetween(config('grid.min_grids'), config('grid.max_grids'))
        ->and($suggestion['profit_per_grid_pct'])->toBeGreaterThan(0)
        ->and($suggestion['leverage'])->toBeBetween(1, config('grid.volatile.max_leverage'))
        ->and($suggestion['stop_loss_pnl_pct'])->toBeGreaterThanOrEqual(-config('grid.volatile.max_stop_loss') * 100)
        ->and($suggestion['take_profit_pnl_pct'])->toBeGreaterThanOrEqual(config('grid.volatile.min_take_profit') * 100)
        ->and($suggestion['metrics']['runtime_minutes'])->toBe(120)
        ->and($suggestion['grade'])->toBe(GridGrade::fromScore($suggestion['score']));
});

it('waits for the price to come back to the fair value and centers the range on that start price', function (float $offsetPct, EntryTrigger $trigger) {
    $candles = volatileCandles();
    $price = lastClose($candles) * (1 + $offsetPct / 100);

    $suggestion = (new VolatileGridAnalyzer)->analyze('PUMPUSDT', $candles, volatileMarket($price), 14.0);
    $start = $suggestion['entry_trigger_price'];

    expect($suggestion['entry_trigger'])->toBe($trigger)
        ->and($start)->toBe($suggestion['metrics']['start_price'])
        ->and($trigger === EntryTrigger::PriceBelow ? $start < $price : $start > $price)->toBeTrue()
        ->and(abs($start - $suggestion['metrics']['fair_value']))->toBeLessThan(abs($price - $suggestion['metrics']['fair_value']))
        ->and($suggestion['lower_price'])->toBeLessThan($start)
        ->and($suggestion['upper_price'])->toBeGreaterThan($start);
})->with([
    'stretched above' => [4.0, EntryTrigger::PriceBelow],
    'stretched below' => [-4.0, EntryTrigger::PriceAbove],
]);

it('skews long and starts in the lower part of the box when the box drifts steadily up', function () {
    config(['grid.volatile.min_runtime_roi' => 0]);
    $candles = volatileCandles(tail: 'drift');
    $price = lastClose($candles);

    $suggestion = (new VolatileGridAnalyzer)->analyze('PUMPUSDT', $candles, volatileMarket($price), 14.0);

    expect($suggestion['mode'])->toBe(GridMode::Long)
        ->and($suggestion['entry_trigger'])->toBe(EntryTrigger::PriceBelow)
        ->and($suggestion['entry_trigger_price'])->toBeLessThan(($suggestion['metrics']['box_low'] + $suggestion['metrics']['box_high']) / 2);
});

it('skips a pair that is not stabilizing yet', function (string $tail) {
    $candles = volatileCandles(tail: $tail);

    expect((new VolatileGridAnalyzer)->analyze('PUMPUSDT', $candles, volatileMarket(lastClose($candles)), 14.0))->toBeNull();
})->with([
    'still moving in the last hour' => ['impulse'],
    'grinding all day without calming down' => ['grind'],
]);

it('qualifies a pair by its daily ATR or, without one, by its range over the last day', function (float $impulse, ?float $dailyAtrPct, bool $qualifies) {
    $candles = volatileCandles(impulse: $impulse);

    $suggestion = (new VolatileGridAnalyzer)->analyze('PUMPUSDT', $candles, volatileMarket(lastClose($candles)), $dailyAtrPct);

    expect($suggestion !== null)->toBe($qualifies);
})->with([
    'large daily ATR, small day range' => [10, 12.0, true],
    'small daily ATR, small day range' => [10, 3.0, false],
    'new listing with a large day range' => [30, null, true],
    'new listing with a small day range' => [10, null, false],
]);

it('skips a pair whose grid would not earn enough over the runtime', function () {
    config(['grid.volatile.min_runtime_roi' => 1.0]);
    $candles = volatileCandles();

    expect((new VolatileGridAnalyzer)->analyze('PUMPUSDT', $candles, volatileMarket(lastClose($candles)), 14.0))->toBeNull();
});

it('needs enough candles to measure the day', function () {
    $candles = array_slice(volatileCandles(), -VolatileGridAnalyzer::MinimumCandles + 1);

    expect((new VolatileGridAnalyzer)->analyze('PUMPUSDT', $candles, volatileMarket(lastClose($candles)), 14.0))->toBeNull();
});
