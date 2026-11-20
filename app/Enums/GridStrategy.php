<?php

namespace App\Enums;

/**
 * Which kind of grid bot a scan looks for.
 */
enum GridStrategy: string
{
    /** Multi-day bots sized from 1h candles, rescanned hourly. */
    case Swing = 'swing';

    /** Short bots of up to a few hours on highly volatile pairs that are stabilizing, sized from 5m candles. */
    case Volatile = 'volatile';

    /**
     * Name of the cache lock that keeps two scans of this strategy from running at once.
     */
    public function lockName(): string
    {
        return match ($this) {
            self::Swing => 'grid-scan',
            self::Volatile => 'grid-scan-volatile',
        };
    }

    /**
     * Minutes after which a scan still marked as running is treated as abandoned.
     */
    public function staleAfterMinutes(): int
    {
        return match ($this) {
            self::Swing => 15,
            self::Volatile => 5,
        };
    }
}
