<?php

namespace App\Models;

use Database\Factories\SymbolStatFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Latest BTC sensitivity of every scanned symbol, kept even when the symbol has no suggestion.
 *
 * @property string $symbol
 * @property float $beta
 * @property float $correlation
 * @property float $shock_beta_down
 * @property float $shock_beta_up
 * @property int $shock_hours_down
 * @property int $shock_hours_up
 * @property float $btc_shock_pct
 * @property Carbon $measured_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['symbol', 'beta', 'correlation', 'shock_beta_down', 'shock_beta_up', 'shock_hours_down', 'shock_hours_up', 'btc_shock_pct', 'measured_at'])]
class SymbolStat extends Model
{
    /** @use HasFactory<SymbolStatFactory> */
    use HasFactory;

    protected $primaryKey = 'symbol';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'beta' => 'float',
            'correlation' => 'float',
            'shock_beta_down' => 'float',
            'shock_beta_up' => 'float',
            'btc_shock_pct' => 'float',
            'measured_at' => 'datetime',
        ];
    }
}
