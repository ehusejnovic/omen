<?php

namespace App\Http\Resources;

use App\Models\GridSuggestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GridSuggestion
 */
class GridSuggestionResource extends JsonResource
{
    /**
     * Transform the resource into an array, sizing margin and PnL in USDT from the viewer's total margin.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $margin = round($request->user()->total_margin * $this->margin_allocation_pct / 100, 2);
        $notionalPerGrid = $margin * $this->leverage / $this->grid_count;

        return [
            'id' => $this->id,
            'symbol' => $this->symbol,
            'mode' => $this->mode,
            'grade' => $this->grade,
            'score' => $this->score,
            'last_price' => $this->last_price,
            'lower_price' => $this->lower_price,
            'upper_price' => $this->upper_price,
            'range_width_pct' => round(($this->upper_price / $this->lower_price - 1) * 100, 2),
            'grid_count' => $this->grid_count,
            'grid_type' => $this->grid_type,
            'grid_spacing_pct' => $this->grid_spacing_pct,
            'profit_per_grid_pct' => $this->profit_per_grid_pct,
            'leverage' => $this->leverage,
            'margin_allocation_pct' => $this->margin_allocation_pct,
            'margin' => $margin,
            'notional_per_grid' => round($notionalPerGrid, 2),
            'trailing_up' => $this->trailing_up,
            'trailing_down' => $this->trailing_down,
            'trailing_limit_price' => $this->trailing_limit_price,
            'below_min_notional' => $notionalPerGrid < ($this->metrics['min_notional'] ?? 5),
            'entry_trigger' => $this->entry_trigger,
            'entry_trigger_price' => $this->entry_trigger_price,
            'take_profit_pnl_pct' => $this->take_profit_pnl_pct,
            'take_profit_usdt' => round($margin * $this->take_profit_pnl_pct / 100, 2),
            'stop_loss_pnl_pct' => $this->stop_loss_pnl_pct,
            'stop_loss_usdt' => round($margin * $this->stop_loss_pnl_pct / 100, 2),
            'estimated_daily_roi_pct' => $this->estimated_daily_roi_pct,
            'quote_volume_24h' => $this->quote_volume_24h,
            'price_change_24h_pct' => $this->price_change_24h_pct,
            'funding_rate_pct' => $this->funding_rate_pct,
            'metrics' => $this->metrics,
            'reasons' => $this->reasons,
        ];
    }
}
