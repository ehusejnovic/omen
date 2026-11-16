<?php

namespace Database\Factories;

use App\Models\FuturesSymbol;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FuturesSymbol>
 */
class FuturesSymbolFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $baseAsset = strtoupper(fake()->unique()->lexify('????'));

        return [
            'symbol' => "{$baseAsset}USDT",
            'base_asset' => $baseAsset,
            'tick_size' => 0.01,
            'is_trading' => true,
        ];
    }

    /**
     * Indicate that Binance no longer lists the contract.
     */
    public function delisted(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_trading' => false,
        ]);
    }
}
