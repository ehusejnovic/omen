<?php

namespace Database\Factories;

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
}
