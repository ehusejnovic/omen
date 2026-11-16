<?php

namespace App\Enums;

/**
 * Where the idea for a journaled grid bot came from.
 */
enum TradeSource: string
{
    case Scanner = 'scanner';
    case Manual = 'manual';
}
