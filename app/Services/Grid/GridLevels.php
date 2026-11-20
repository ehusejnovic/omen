<?php

namespace App\Services\Grid;

use App\Enums\GridMode;

/**
 * Grid line prices and the position PnL a grid bot carries as the price moves through them.
 */
class GridLevels
{
    /**
     * Grid line prices from the lower to the upper limit.
     *
     * @return list<float>
     */
    public static function prices(float $lower, float $upper, int $gridCount, bool $geometric): array
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
    public static function unrealizedPnl(GridMode $mode, array $levels, float $startPrice, float $exitPrice, float $qtyPerGrid): float
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
}
