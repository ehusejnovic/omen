<?php

namespace Database\Factories;

use App\Models\SymbolStat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SymbolStat>
 */
class SymbolStatFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'symbol' => strtoupper(fake()->unique()->lexify('???')).'USDT',
            'beta' => 1.2,
            'correlation' => 0.8,
            'shock_beta_down' => 1.5,
            'shock_beta_up' => 1.1,
            'shock_hours_down' => 8,
            'shock_hours_up' => 7,
            'btc_shock_pct' => 5,
            'measured_at' => now(),
        ];
    }
}
