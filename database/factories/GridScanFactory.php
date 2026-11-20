<?php

namespace Database\Factories;

use App\Enums\GridStrategy;
use App\Models\GridScan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GridScan>
 */
class GridScanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'status' => GridScan::StatusCompleted,
            'strategy' => GridStrategy::Swing,
            'symbols_scanned' => 100,
            'suggestions_count' => 100,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ];
    }

    /**
     * Indicate that the scan is still running.
     */
    public function running(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => GridScan::StatusRunning,
            'started_at' => now(),
            'finished_at' => null,
        ]);
    }

    /**
     * Indicate that the scan looked for short bots on volatile, stabilizing pairs.
     */
    public function volatile(): static
    {
        return $this->state(fn (array $attributes) => [
            'strategy' => GridStrategy::Volatile,
            'spacing_multiplier' => null,
            'range_horizon_days' => null,
        ]);
    }
}
