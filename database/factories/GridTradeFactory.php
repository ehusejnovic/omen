<?php

namespace Database\Factories;

use App\Enums\GridGrade;
use App\Enums\GridMode;
use App\Enums\TradeSource;
use App\Models\FuturesSymbol;
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
            'source' => TradeSource::Manual,
            'margin' => 100,
            'leverage' => 5,
            'lower_price' => 90,
            'upper_price' => 110,
            'grid_count' => 40,
            'trailing_up' => false,
            'trailing_down' => false,
            'range_horizon_days' => null,
            'matched_trades' => 25,
            'matched_profit' => null,
            'pnl' => 4.5,
            'started_at' => now()->subDays(3),
            'ended_at' => now()->subDay(),
            'notes' => null,
        ];
    }

    /**
     * Register the trade's symbol as a Binance contract when no test created it, since trades reference it.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (GridTrade $trade): void {
            FuturesSymbol::query()->firstOrCreate(
                ['symbol' => $trade->symbol],
                ['base_asset' => str($trade->symbol)->beforeLast('USDT')->toString() ?: $trade->symbol],
            );
        });
    }

    /**
     * Indicate that the bot was suggested by the scanner with the given grade.
     */
    public function fromScanner(GridGrade $grade = GridGrade::A): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => TradeSource::Scanner,
            'suggested_grade' => $grade,
        ]);
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
