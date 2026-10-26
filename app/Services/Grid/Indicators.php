<?php

namespace App\Services\Grid;

/**
 * Stateless technical indicators over plain float series, oldest value first.
 */
class Indicators
{
    /**
     * Exponential moving average series, seeded with the first value.
     *
     * @param  list<float>  $values
     * @return list<float>
     */
    public static function ema(array $values, int $period): array
    {
        $multiplier = 2 / ($period + 1);
        $series = [];
        $previous = $values[0];

        foreach ($values as $value) {
            $previous = ($value - $previous) * $multiplier + $previous;
            $series[] = $previous;
        }

        return $series;
    }

    /**
     * Latest Wilder RSI value.
     *
     * @param  list<float>  $closes
     */
    public static function rsi(array $closes, int $period = 14): float
    {
        $averageGain = 0.0;
        $averageLoss = 0.0;
        $count = count($closes);

        for ($i = 1; $i <= $period; $i++) {
            $change = $closes[$i] - $closes[$i - 1];
            $averageGain += max($change, 0) / $period;
            $averageLoss += max(-$change, 0) / $period;
        }

        for ($i = $period + 1; $i < $count; $i++) {
            $change = $closes[$i] - $closes[$i - 1];
            $averageGain = ($averageGain * ($period - 1) + max($change, 0)) / $period;
            $averageLoss = ($averageLoss * ($period - 1) + max(-$change, 0)) / $period;
        }

        if ($averageLoss == 0.0) {
            return $averageGain == 0.0 ? 50.0 : 100.0;
        }

        return 100 - 100 / (1 + $averageGain / $averageLoss);
    }

    /**
     * True range of every candle after the first.
     *
     * @param  list<float>  $highs
     * @param  list<float>  $lows
     * @param  list<float>  $closes
     * @return list<float>
     */
    public static function trueRanges(array $highs, array $lows, array $closes): array
    {
        $ranges = [];

        for ($i = 1, $count = count($closes); $i < $count; $i++) {
            $ranges[] = max(
                $highs[$i] - $lows[$i],
                abs($highs[$i] - $closes[$i - 1]),
                abs($lows[$i] - $closes[$i - 1]),
            );
        }

        return $ranges;
    }

    /**
     * Latest Wilder average true range.
     *
     * @param  list<float>  $highs
     * @param  list<float>  $lows
     * @param  list<float>  $closes
     */
    public static function atr(array $highs, array $lows, array $closes, int $period = 14): float
    {
        $ranges = self::trueRanges($highs, $lows, $closes);
        $atr = array_sum(array_slice($ranges, 0, $period)) / $period;

        for ($i = $period, $count = count($ranges); $i < $count; $i++) {
            $atr = ($atr * ($period - 1) + $ranges[$i]) / $period;
        }

        return $atr;
    }

    /**
     * Latest Wilder average directional index (trend strength, 0-100).
     *
     * @param  list<float>  $highs
     * @param  list<float>  $lows
     * @param  list<float>  $closes
     */
    public static function adx(array $highs, array $lows, array $closes, int $period = 14): float
    {
        $ranges = self::trueRanges($highs, $lows, $closes);
        $plusMoves = [];
        $minusMoves = [];

        for ($i = 1, $count = count($closes); $i < $count; $i++) {
            $up = $highs[$i] - $highs[$i - 1];
            $down = $lows[$i - 1] - $lows[$i];
            $plusMoves[] = $up > $down && $up > 0 ? $up : 0.0;
            $minusMoves[] = $down > $up && $down > 0 ? $down : 0.0;
        }

        $smoothedRange = array_sum(array_slice($ranges, 0, $period));
        $smoothedPlus = array_sum(array_slice($plusMoves, 0, $period));
        $smoothedMinus = array_sum(array_slice($minusMoves, 0, $period));
        $directionalIndexes = [];

        for ($i = $period, $count = count($ranges); $i <= $count; $i++) {
            if ($i > $period) {
                $smoothedRange = $smoothedRange - $smoothedRange / $period + $ranges[$i - 1];
                $smoothedPlus = $smoothedPlus - $smoothedPlus / $period + $plusMoves[$i - 1];
                $smoothedMinus = $smoothedMinus - $smoothedMinus / $period + $minusMoves[$i - 1];
            }

            if ($smoothedRange == 0.0) {
                $directionalIndexes[] = 0.0;

                continue;
            }

            $plus = 100 * $smoothedPlus / $smoothedRange;
            $minus = 100 * $smoothedMinus / $smoothedRange;
            $directionalIndexes[] = $plus + $minus == 0.0 ? 0.0 : 100 * abs($plus - $minus) / ($plus + $minus);
        }

        $adx = array_sum(array_slice($directionalIndexes, 0, $period)) / $period;

        for ($i = $period, $count = count($directionalIndexes); $i < $count; $i++) {
            $adx = ($adx * ($period - 1) + $directionalIndexes[$i]) / $period;
        }

        return $adx;
    }

    /**
     * Kaufman efficiency ratio: net move divided by the path travelled (0 = pure chop, 1 = straight line).
     *
     * @param  list<float>  $closes
     */
    public static function efficiencyRatio(array $closes): float
    {
        $path = 0.0;

        for ($i = 1, $count = count($closes); $i < $count; $i++) {
            $path += abs($closes[$i] - $closes[$i - 1]);
        }

        return $path == 0.0 ? 0.0 : abs(end($closes) - $closes[0]) / $path;
    }

    /**
     * Log returns between consecutive closes.
     *
     * @param  list<float>  $closes
     * @return list<float>
     */
    public static function logReturns(array $closes): array
    {
        $returns = [];

        for ($i = 1, $count = count($closes); $i < $count; $i++) {
            $returns[] = log($closes[$i] / $closes[$i - 1]);
        }

        return $returns;
    }

    /**
     * Sample standard deviation.
     *
     * @param  list<float>  $values
     */
    public static function standardDeviation(array $values): float
    {
        $count = count($values);

        if ($count < 2) {
            return 0.0;
        }

        $mean = array_sum($values) / $count;
        $squares = array_sum(array_map(fn (float $value): float => ($value - $mean) ** 2, $values));

        return sqrt($squares / ($count - 1));
    }

    /**
     * Linearly interpolated percentile, where $percentile is 0-100.
     *
     * @param  list<float>  $values
     */
    public static function percentile(array $values, float $percentile): float
    {
        sort($values);
        $position = ($percentile / 100) * (count($values) - 1);
        $lower = (int) floor($position);
        $upper = (int) ceil($position);

        return $values[$lower] + ($values[$upper] - $values[$lower]) * ($position - $lower);
    }

    /**
     * Least-squares slope per bar.
     *
     * @param  list<float>  $values
     */
    public static function slope(array $values): float
    {
        $count = count($values);
        $meanX = ($count - 1) / 2;
        $meanY = array_sum($values) / $count;
        $numerator = 0.0;
        $denominator = 0.0;

        foreach ($values as $x => $y) {
            $numerator += ($x - $meanX) * ($y - $meanY);
            $denominator += ($x - $meanX) ** 2;
        }

        return $denominator == 0.0 ? 0.0 : $numerator / $denominator;
    }

    /**
     * Number of times the closes cross the matching moving average value.
     *
     * @param  list<float>  $closes
     * @param  list<float>  $average
     */
    public static function crossings(array $closes, array $average): int
    {
        $crossings = 0;
        $previousSide = 0;

        foreach ($closes as $i => $close) {
            $side = $close <=> $average[$i];

            if ($side !== 0 && $previousSide !== 0 && $side !== $previousSide) {
                $crossings++;
            }

            if ($side !== 0) {
                $previousSide = $side;
            }
        }

        return $crossings;
    }

    /**
     * Pearson correlation of two equally long series.
     *
     * @param  list<float>  $x
     * @param  list<float>  $y
     */
    public static function correlation(array $x, array $y): float
    {
        $deviationX = self::standardDeviation($x);
        $deviationY = self::standardDeviation($y);

        return $deviationX == 0.0 || $deviationY == 0.0 ? 0.0 : self::covariance($x, $y) / ($deviationX * $deviationY);
    }

    /**
     * How much $y moves for each unit $x moves (least-squares slope of $y on $x).
     *
     * @param  list<float>  $x
     * @param  list<float>  $y
     */
    public static function beta(array $x, array $y): float
    {
        $varianceX = self::standardDeviation($x) ** 2;

        return $varianceX == 0.0 ? 0.0 : self::covariance($x, $y) / $varianceX;
    }

    /**
     * Least-squares slope through the origin, which stays stable for a handful of observations.
     *
     * @param  list<float>  $x
     * @param  list<float>  $y
     */
    public static function betaThroughOrigin(array $x, array $y): float
    {
        $squares = 0.0;
        $products = 0.0;

        foreach ($x as $i => $value) {
            $squares += $value ** 2;
            $products += $value * $y[$i];
        }

        return $squares == 0.0 ? 0.0 : $products / $squares;
    }

    /**
     * Sample covariance of two equally long series.
     *
     * @param  list<float>  $x
     * @param  list<float>  $y
     */
    public static function covariance(array $x, array $y): float
    {
        $count = count($x);

        if ($count < 2) {
            return 0.0;
        }

        $meanX = array_sum($x) / $count;
        $meanY = array_sum($y) / $count;
        $sum = 0.0;

        foreach ($x as $i => $value) {
            $sum += ($value - $meanX) * ($y[$i] - $meanY);
        }

        return $sum / ($count - 1);
    }
}
