<?php

namespace App\Models;

use Database\Factories\GridScanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $status
 * @property int $symbols_scanned
 * @property int $suggestions_count
 * @property float|null $spacing_multiplier
 * @property string|null $error
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['status', 'symbols_scanned', 'suggestions_count', 'spacing_multiplier', 'error', 'started_at', 'finished_at'])]
class GridScan extends Model
{
    /** @use HasFactory<GridScanFactory> */
    use HasFactory, MassPrunable;

    public const string StatusRunning = 'running';

    public const string StatusCompleted = 'completed';

    public const string StatusFailed = 'failed';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'spacing_multiplier' => 'float',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<GridSuggestion, $this>
     */
    public function suggestions(): HasMany
    {
        return $this->hasMany(GridSuggestion::class);
    }

    /**
     * Get the prunable model query.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(config('grid.keep_scans_for_days')));
    }
}
