<?php

namespace App\Models;

use App\Enums\EntryTrigger;
use App\Enums\GridGrade;
use App\Enums\GridMode;
use Database\Factories\GridSuggestionFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $grid_scan_id
 * @property string $symbol
 * @property GridMode $mode
 * @property GridGrade $grade
 * @property int $score
 * @property float $last_price
 * @property float $lower_price
 * @property float $upper_price
 * @property int $grid_count
 * @property string $grid_type
 * @property float $grid_spacing_pct
 * @property float $profit_per_grid_pct
 * @property int $leverage
 * @property float $margin_allocation_pct
 * @property bool $trailing_up
 * @property bool $trailing_down
 * @property float|null $trailing_limit_price
 * @property EntryTrigger $entry_trigger
 * @property float|null $entry_trigger_price
 * @property float $take_profit_pnl_pct
 * @property float $stop_loss_pnl_pct
 * @property float $estimated_daily_roi_pct
 * @property float $quote_volume_24h
 * @property float $price_change_24h_pct
 * @property float $funding_rate_pct
 * @property array<string, float|int|string|null> $metrics
 * @property list<string> $reasons
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Guarded(['id'])]
class GridSuggestion extends Model
{
    /** @use HasFactory<GridSuggestionFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => GridMode::class,
            'grade' => GridGrade::class,
            'entry_trigger' => EntryTrigger::class,
            'last_price' => 'float',
            'lower_price' => 'float',
            'upper_price' => 'float',
            'grid_spacing_pct' => 'float',
            'profit_per_grid_pct' => 'float',
            'margin_allocation_pct' => 'float',
            'trailing_up' => 'boolean',
            'trailing_down' => 'boolean',
            'trailing_limit_price' => 'float',
            'entry_trigger_price' => 'float',
            'take_profit_pnl_pct' => 'float',
            'stop_loss_pnl_pct' => 'float',
            'estimated_daily_roi_pct' => 'float',
            'quote_volume_24h' => 'float',
            'price_change_24h_pct' => 'float',
            'funding_rate_pct' => 'float',
            'metrics' => 'array',
            'reasons' => 'array',
        ];
    }

    /**
     * @return BelongsTo<GridScan, $this>
     */
    public function scan(): BelongsTo
    {
        return $this->belongsTo(GridScan::class, 'grid_scan_id');
    }
}
