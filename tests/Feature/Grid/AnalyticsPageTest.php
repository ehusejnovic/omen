<?php

use App\Enums\GridGrade;
use App\Enums\GridMode;
use App\Models\GridTrade;
use App\Models\SymbolStat;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('redirects guests to the login page', function () {
    $this->get(route('analytics'))->assertRedirect(route('login'));
});

it('summarizes only the user\'s own finished trades', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    GridTrade::factory()->for($user)->create(['symbol' => 'WINUSDT', 'margin' => 100, 'pnl' => 20, 'matched_trades' => 30, 'started_at' => now()->subDays(4), 'ended_at' => now()->subDays(2)]);
    GridTrade::factory()->for($user)->create(['symbol' => 'LOSSUSDT', 'margin' => 100, 'pnl' => -5.5, 'matched_trades' => 10, 'started_at' => now()->subDays(2), 'ended_at' => now()->subHours(36)]);
    GridTrade::factory()->for($user)->create(['symbol' => 'SMALLUSDT', 'margin' => 200, 'pnl' => 10, 'matched_trades' => 6, 'started_at' => now()->subDays(2), 'ended_at' => now()->subDay()]);
    GridTrade::factory()->for($user)->running()->create(['symbol' => 'LIVEUSDT', 'matched_trades' => 4, 'started_at' => now()->subHours(6)]);
    GridTrade::factory()->create(['symbol' => 'OTHERUSDT', 'pnl' => -500]);

    $this->actingAs($user)
        ->get(route('analytics'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('analytics')
            ->has('trades', 4)
            ->where('summary', [
                'total_trades' => 4,
                'running_trades' => 1,
                'closed_trades' => 3,
                'total_pnl' => 24.5,
                'wins' => 2,
                'losses' => 1,
                'win_rate_pct' => 66.7,
                'gross_profit' => 30,
                'gross_loss' => 5.5,
                'profit_factor' => 5.45,
                'average_win' => 15,
                'average_loss' => -5.5,
                'expectancy' => 8.17,
                'average_roi_pct' => 6.5,
                'daily_return_pct' => 5.44,
                'max_drawdown' => -5.5,
                'longest_win_streak' => 1,
                'longest_loss_streak' => 1,
                'best_trade' => ['id' => GridTrade::query()->where('symbol', 'WINUSDT')->value('id'), 'symbol' => 'WINUSDT', 'pnl' => 20, 'roi_pct' => 20],
                'worst_trade' => ['id' => GridTrade::query()->where('symbol', 'LOSSUSDT')->value('id'), 'symbol' => 'LOSSUSDT', 'pnl' => -5.5, 'roi_pct' => -5.5],
                'total_matched_trades' => 50,
                'average_duration_seconds' => 28 * 3600,
            ]));
});

it('measures drawdown and streaks in the order trades ended', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    foreach ([10, -4, -6, 3, 8, 5, -2] as $index => $pnl) {
        GridTrade::factory()->for($user)->create(['pnl' => $pnl, 'started_at' => now()->subDays(10), 'ended_at' => now()->subDays(9 - $index)]);
    }

    $this->actingAs($user)
        ->get(route('analytics'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.max_drawdown', -10)
            ->where('summary.longest_win_streak', 3)
            ->where('summary.longest_loss_streak', 2));
});

it('returns empty statistics when nothing has finished yet', function () {
    $user = User::factory()->create();
    GridTrade::factory()->for($user)->running()->create();

    $this->actingAs($user)
        ->get(route('analytics'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.closed_trades', 0)
            ->where('summary.win_rate_pct', null)
            ->where('summary.profit_factor', null)
            ->where('summary.expectancy', null)
            ->where('summary.best_trade', null)
            ->where('summary.max_drawdown', 0)
            ->where('pnlBreakdown', null)
            ->where('bySymbol.0.closed_with_pnl', 0));
});

it('splits the final PnL of every finished trade into grid profit, the position at close and unsplit trades', function () {
    $user = User::factory()->create();
    GridTrade::factory()->for($user)->create(['pnl' => 20, 'matched_profit' => 25]);
    GridTrade::factory()->for($user)->create(['pnl' => -5.5, 'matched_profit' => 2]);
    GridTrade::factory()->for($user)->create(['pnl' => 10, 'matched_profit' => 8]);
    GridTrade::factory()->for($user)->create(['pnl' => 50, 'matched_profit' => null]);
    GridTrade::factory()->for($user)->running()->create(['matched_profit' => 40]);

    $this->actingAs($user)
        ->get(route('analytics'))
        ->assertInertia(fn (Assert $page) => $page->where('pnlBreakdown', [
            'trades' => 4,
            'profitable' => 3,
            'pnl' => 74.5,
            'matched_profit' => 35,
            'position_pnl' => -10.5,
            'unsplit_trades' => 1,
            'unsplit_pnl' => 50,
        ]));
});

it('compares results by grade, best grade first and trades without a grade last', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    GridTrade::factory()->for($user)->create(['suggested_grade' => null, 'pnl' => 1]);
    GridTrade::factory()->for($user)->create(['suggested_grade' => GridGrade::B, 'pnl' => -3]);
    GridTrade::factory()->for($user)->create(['suggested_grade' => GridGrade::APlus, 'margin' => 100, 'pnl' => 12, 'matched_trades' => 20, 'started_at' => now()->subDays(2), 'ended_at' => now()->subDay()]);
    GridTrade::factory()->for($user)->create(['suggested_grade' => GridGrade::APlus, 'margin' => 100, 'pnl' => -4, 'matched_trades' => 10, 'started_at' => now()->subDays(2), 'ended_at' => now()->subDay()]);
    GridTrade::factory()->for($user)->running()->create(['suggested_grade' => GridGrade::APlus, 'matched_trades' => 6, 'started_at' => now()->subDay()]);

    $this->actingAs($user)
        ->get(route('analytics'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('breakdowns.grade.0', [
                'label' => 'A+',
                'trades' => 3,
                'closed_with_pnl' => 2,
                'total_pnl' => 8,
                'win_rate_pct' => 50,
                'average_roi_pct' => 4,
                'profit_factor' => 3,
                'matched_per_day' => 12,
            ])
            ->where('breakdowns.grade.1.label', 'B')
            ->where('breakdowns.grade.2.label', 'Not set'));
});

it('orders leverage, horizon, duration and mode groups', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    GridTrade::factory()->for($user)->create(['leverage' => 10, 'range_horizon_days' => null, 'mode' => GridMode::Short, 'started_at' => now()->subDays(3), 'ended_at' => now()]);
    GridTrade::factory()->for($user)->create(['leverage' => 3, 'range_horizon_days' => 7, 'mode' => GridMode::Normal, 'started_at' => now()->subHours(13), 'ended_at' => now()]);
    GridTrade::factory()->for($user)->create(['leverage' => 5, 'range_horizon_days' => 5, 'mode' => GridMode::Long, 'started_at' => now()->subHours(2), 'ended_at' => now()]);
    GridTrade::factory()->for($user)->running()->create(['leverage' => 5, 'range_horizon_days' => 5, 'mode' => GridMode::Long]);

    $this->actingAs($user)
        ->get(route('analytics'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('breakdowns.leverage', fn ($rows) => collect($rows)->pluck('label')->all() === ['3x', '5x', '10x'])
            ->where('breakdowns.horizon', fn ($rows) => collect($rows)->pluck('label')->all() === ['5 days', '7 days', 'Not set'])
            ->where('breakdowns.duration', fn ($rows) => collect($rows)->pluck('label')->all() === ['Under 6h', '12–24h', 'Over 2 days'])
            ->where('breakdowns.mode', fn ($rows) => collect($rows)->pluck('label')->all() === ['Normal', 'Long', 'Short']));
});

it('ranks pairs by total PnL', function () {
    $user = User::factory()->create();
    GridTrade::factory()->for($user)->create(['symbol' => 'ZECUSDT', 'pnl' => 5]);
    GridTrade::factory()->for($user)->create(['symbol' => 'QNTUSDT', 'pnl' => 4]);
    GridTrade::factory()->for($user)->create(['symbol' => 'QNTUSDT', 'pnl' => 6]);
    GridTrade::factory()->for($user)->create(['symbol' => 'HYPEUSDT', 'pnl' => -30]);

    $this->actingAs($user)
        ->get(route('analytics'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('bySymbol', 3)
            ->where('bySymbol.0.label', 'QNTUSDT')
            ->where('bySymbol.0.trades', 2)
            ->where('bySymbol.0.total_pnl', 10)
            ->where('bySymbol.1.label', 'ZECUSDT')
            ->where('bySymbol.2.label', 'HYPEUSDT'));
});

function seedRunningPortfolio(User $user): void
{
    SymbolStat::factory()->create(['symbol' => 'BTCUSDT', 'beta' => 1, 'shock_beta_down' => 1, 'shock_beta_up' => 1, 'btc_shock_pct' => 5]);
    SymbolStat::factory()->create(['symbol' => 'ALTUSDT', 'beta' => 1.2, 'shock_beta_down' => 1.5, 'shock_beta_up' => 1.1, 'btc_shock_pct' => 5]);
    SymbolStat::factory()->create(['symbol' => 'MEMEUSDT', 'beta' => 2, 'shock_beta_down' => 2, 'shock_beta_up' => 2, 'btc_shock_pct' => 5]);

    GridTrade::factory()->for($user)->running()->create(['symbol' => 'ALTUSDT', 'mode' => GridMode::Long, 'margin' => 100, 'leverage' => 5]);
    GridTrade::factory()->for($user)->running()->create(['symbol' => 'BTCUSDT', 'mode' => GridMode::Short, 'margin' => 100, 'leverage' => 2]);
    GridTrade::factory()->for($user)->running()->create(['symbol' => 'MEMEUSDT', 'mode' => GridMode::Normal, 'margin' => 100, 'leverage' => 4]);
    GridTrade::factory()->for($user)->running()->create(['symbol' => 'NEWUSDT', 'mode' => GridMode::Long]);
    GridTrade::factory()->for($user)->create(['symbol' => 'ALTUSDT', 'mode' => GridMode::Long, 'margin' => 5000, 'leverage' => 10]);
}

it('estimates how running bots react together to a sudden BTC move', function () {
    $user = User::factory()->create(['total_margin' => 1000]);
    seedRunningPortfolio($user);

    $this->actingAs($user)
        ->get(route('analytics'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('exposure.btc_shock_pct', 5)
            ->where('exposure.measured_trades', 3)
            ->where('exposure.unmeasured_symbols', ['NEWUSDT'])
            ->where('exposure.net_beta_exposure', 400)
            ->where('exposure.down_shock_pnl', -47.5)
            ->where('exposure.up_shock_pnl', -2.5)
            ->where('exposure.worst_shock_pnl', -47.5)
            ->where('exposure.worst_shock_pct_of_margin', 4.75)
            ->where('exposure.exceeds_limit', false)
            ->has('exposure.betas', 3));
});

it('warns when a sudden BTC move could cost more than the portfolio limit', function () {
    $user = User::factory()->create(['total_margin' => 200]);
    seedRunningPortfolio($user);

    $this->actingAs($user)
        ->get(route('analytics'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('exposure.worst_shock_pct_of_margin', 23.75)
            ->where('exposure.exceeds_limit', true));
});

it('omits BTC exposure when no bots are running', function () {
    $user = User::factory()->create();
    GridTrade::factory()->for($user)->create();

    $this->actingAs($user)
        ->get(route('analytics'))
        ->assertInertia(fn (Assert $page) => $page->where('exposure', null));
});
