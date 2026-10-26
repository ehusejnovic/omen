<?php

namespace App\Models;

use App\Enums\GridGrade;
use App\Enums\GridMode;
use Database\Factories\GridTradeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A grid bot the user actually ran, journaled by hand.
 *
 * @property int $id
 * @property int $user_id
 * @property string $symbol
 * @property GridMode $mode
 * @property GridGrade|null $suggested_grade
 * @property float $margin
 * @property int $leverage
 * @property float|null $lower_price
 * @property float|null $upper_price
 * @property int|null $grid_count
 * @property bool $trailing_up
 * @property bool $trailing_down
 * @property int $matched_trades
 * @property float|null $pnl
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['symbol', 'mode', 'suggested_grade', 'margin', 'leverage', 'lower_price', 'upper_price', 'grid_count', 'trailing_up', 'trailing_down', 'matched_trades', 'pnl', 'started_at', 'ended_at', 'notes'])]
class GridTrade extends Model
{
    /** @use HasFactory<GridTradeFactory> */
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
            'suggested_grade' => GridGrade::class,
            'margin' => 'float',
            'lower_price' => 'float',
            'upper_price' => 'float',
            'trailing_up' => 'boolean',
            'trailing_down' => 'boolean',
            'pnl' => 'float',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Running time in seconds, up to now while the bot is still running.
     */
    public function durationInSeconds(): int
    {
        return (int) $this->started_at->diffInSeconds($this->ended_at ?? now());
    }
}
