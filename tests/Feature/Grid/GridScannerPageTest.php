<?php

use App\Jobs\RunGridScan;
use App\Models\GridScan;
use App\Models\GridSuggestion;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

it('shows the latest completed scan sorted by score with margin sized from the user total margin', function () {
    $user = User::factory()->create(['total_margin' => 2000]);
    $olderScan = GridScan::factory()->create();
    GridSuggestion::factory()->for($olderScan, 'scan')->create(['symbol' => 'OLDUSDT']);
    $latestScan = GridScan::factory()->create();
    GridSuggestion::factory()->for($latestScan, 'scan')->create(['symbol' => 'LOWUSDT', 'score' => 40]);
    GridSuggestion::factory()->for($latestScan, 'scan')->create([
        'symbol' => 'TOPUSDT',
        'score' => 90,
        'margin_allocation_pct' => 15,
        'leverage' => 4,
        'grid_count' => 50,
        'take_profit_pnl_pct' => 10,
        'stop_loss_pnl_pct' => -25,
    ]);
    GridScan::factory()->running()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('scan.id', $latestScan->id)
            ->where('isScanning', true)
            ->where('totalMargin', 2000)
            ->has('suggestions', 2)
            ->where('suggestions.0.symbol', 'TOPUSDT')
            ->where('suggestions.0.margin', 300)
            ->where('suggestions.0.notional_per_grid', 24)
            ->where('suggestions.0.below_min_notional', false)
            ->where('suggestions.0.take_profit_usdt', 30)
            ->where('suggestions.0.stop_loss_usdt', -75)
            ->where('suggestions.1.symbol', 'LOWUSDT'));
});

it('flags suggestions whose grid orders fall below the Binance minimum notional', function () {
    $user = User::factory()->create(['total_margin' => 100]);
    GridSuggestion::factory()->create([
        'margin_allocation_pct' => 5,
        'leverage' => 2,
        'grid_count' => 40,
        'metrics' => ['min_notional' => 5, 'tick_size' => 0.01, 'score_breakdown' => []],
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('suggestions.0.notional_per_grid', 0.25)
            ->where('suggestions.0.below_min_notional', true));
});

it('renders an empty scanner before the first scan finishes', function () {
    GridScan::factory()->running()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('scan', null)
            ->has('suggestions', 0));
});

it('queues a scan on demand', function () {
    Queue::fake([RunGridScan::class]);

    $this->actingAs(User::factory()->create())
        ->post(route('grid-scans.store'))
        ->assertRedirect(route('dashboard'));

    Queue::assertPushed(RunGridScan::class);
});

it('redirects guests away from queueing a scan', function () {
    Queue::fake([RunGridScan::class]);

    $this->post(route('grid-scans.store'))->assertRedirect(route('login'));

    Queue::assertNotPushed(RunGridScan::class);
});
