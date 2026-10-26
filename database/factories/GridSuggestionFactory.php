<?php

namespace Database\Factories;

use App\Enums\EntryTrigger;
use App\Enums\GridGrade;
use App\Enums\GridMode;
use App\Models\GridScan;
use App\Models\GridSuggestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GridSuggestion>
 */
class GridSuggestionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $score = fake()->numberBetween(20, 95);

        return [
            'grid_scan_id' => GridScan::factory(),
            'symbol' => strtoupper(fake()->unique()->lexify('???')).'USDT',
            'mode' => fake()->randomElement(GridMode::cases()),
            'grade' => GridGrade::fromScore($score),
            'score' => $score,
            'last_price' => 100,
            'lower_price' => 90,
            'upper_price' => 110,
            'grid_count' => 40,
            'grid_type' => 'arithmetic',
            'grid_spacing_pct' => 0.5,
            'profit_per_grid_pct' => 0.46,
            'leverage' => 5,
            'margin_allocation_pct' => 10,
            'trailing_up' => false,
            'trailing_down' => false,
            'trailing_limit_price' => null,
            'entry_trigger' => EntryTrigger::Immediate,
            'entry_trigger_price' => null,
            'take_profit_pnl_pct' => 8,
            'stop_loss_pnl_pct' => -24,
            'estimated_daily_roi_pct' => 1.1,
            'quote_volume_24h' => 500_000_000,
            'price_change_24h_pct' => 1.5,
            'funding_rate_pct' => 0.01,
            'metrics' => ['min_notional' => 5, 'tick_size' => 0.01, 'score_breakdown' => []],
            'reasons' => ['Range-bound.'],
        ];
    }
}
