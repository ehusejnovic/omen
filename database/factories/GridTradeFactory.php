<?php

namespace Database\Factories;

use App\Enums\GridMode;
use App\Models\GridTrade;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GridTrade>
 */
class GridTradeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'symbol' => strtoupper(fake()->lexify('???')).'USDT',
            'mode' => fake()->randomElement(GridMode::cases()),
            'margin' => 100,
            'leverage' => 5,
            'lower_price' => 90,
            'upper_price' => 110,
            'grid_count' => 40,
            'trailing_up' => false,
            'trailing_down' => false,
            'range_horizon_days' => null,
            'matched_trades' => 25,
            'pnl' => 4.5,
            'started_at' => now()->subDays(3),
            'ended_at' => now()->subDay(),
            'notes' => null,
        ];
    }

    /**
     * Indicate that the bot is still running.
     */
    public function running(): static
    {
        return $this->state(fn (array $attributes) => [
            'ended_at' => null,
            'pnl' => null,
        ]);
    }
}
