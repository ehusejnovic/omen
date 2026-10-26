<?php

namespace App\Enums;

enum EntryTrigger: string
{
    case Immediate = 'immediate';
    case PriceBelow = 'below';
    case PriceAbove = 'above';
}
