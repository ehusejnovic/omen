<?php

namespace App\Http\Resources;

use App\Models\GridTrade;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GridTrade
 */
class GridTradeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'symbol' => $this->symbol,
            'mode' => $this->mode,
            'suggested_grade' => $this->suggested_grade,
            'margin' => $this->margin,
            'leverage' => $this->leverage,
            'lower_price' => $this->lower_price,
            'upper_price' => $this->upper_price,
            'grid_count' => $this->grid_count,
            'trailing_up' => $this->trailing_up,
            'trailing_down' => $this->trailing_down,
            'matched_trades' => $this->matched_trades,
            'pnl' => $this->pnl,
            'roi_pct' => $this->pnl === null ? null : round($this->pnl / $this->margin * 100, 2),
            'started_at' => $this->started_at->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'duration_seconds' => $this->durationInSeconds(),
            'notes' => $this->notes,
        ];
    }
}
