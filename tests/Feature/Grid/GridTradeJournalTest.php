<?php

use App\Enums\GridGrade;
use App\Enums\GridMode;
use App\Models\GridTrade;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array<string, mixed>
 */
function journalPayload(array $overrides = []): array
{
    return [
        'symbol' => ' btcusdt ',
        'mode' => 'long',
        'suggested_grade' => 'A',
        'margin' => '250.50',
        'leverage' => 5,
        'lower_price' => '60000',
        'upper_price' => '70000',
        'grid_count' => 40,
        'trailing_up' => true,
        'trailing_down' => false,
        'range_horizon_days' => 5,
        'matched_trades' => 37,
        'matched_profit' => '8.15',
        'pnl' => '-12.40',
        'started_at' => '2026-10-20T08:00:00.000Z',
        'ended_at' => '2026-10-22T14:30:00.000Z',
        'notes' => 'Stopped out on the breakdown.',
        ...$overrides,
    ];
}

it('redirects guests to the login page', function () {
    $this->get(route('grid-trades.index'))->assertRedirect(route('login'));
});

it('lists only the user\'s own trades, newest first', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    GridTrade::factory()->for($user)->create([
        'symbol' => 'WINUSDT',
        'matched_trades' => 30,
        'pnl' => 20,
        'started_at' => now()->subDays(4),
        'ended_at' => now()->subDays(2),
    ]);
    GridTrade::factory()->for($user)->create([
        'symbol' => 'LOSSUSDT',
        'matched_trades' => 10,
        'pnl' => -5.5,
        'started_at' => now()->subDays(3),
        'ended_at' => now()->subDays(3)->addHours(12),
    ]);
    GridTrade::factory()->for($user)->running()->create([
        'symbol' => 'LIVEUSDT',
        'matched_trades' => 4,
        'started_at' => now()->subHours(6),
    ]);
    GridTrade::factory()->create(['symbol' => 'OTHERUSDT']);

    $this->actingAs($user)
        ->get(route('grid-trades.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('journal')
            ->has('trades', 3)
            ->where('trades.0.symbol', 'LIVEUSDT')
            ->where('trades.0.duration_seconds', 6 * 3600)
            ->where('trades.1.symbol', 'LOSSUSDT')
            ->where('trades.1.duration_seconds', 12 * 3600)
            ->where('trades.1.roi_pct', -5.5)
            ->where('rangeHorizonOptions', [3, 5, 7]));
});

it('journals a trade for the signed-in user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('grid-trades.store'), journalPayload())
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('grid-trades.index'));

    $trade = $user->gridTrades()->sole();

    expect($trade->symbol)->toBe('BTCUSDT')
        ->and($trade->mode)->toBe(GridMode::Long)
        ->and($trade->suggested_grade)->toBe(GridGrade::A)
        ->and($trade->margin)->toBe(250.5)
        ->and($trade->trailing_up)->toBeTrue()
        ->and($trade->trailing_down)->toBeFalse()
        ->and($trade->range_horizon_days)->toBe(5)
        ->and($trade->matched_trades)->toBe(37)
        ->and($trade->matched_profit)->toBe(8.15)
        ->and($trade->pnl)->toBe(-12.4)
        ->and($trade->started_at->toIso8601String())->toBe('2026-10-20T08:00:00+00:00')
        ->and($trade->durationInSeconds())->toBe((2 * 24 + 6) * 3600 + 30 * 60);
});

it('journals a running trade with only the required fields', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('grid-trades.store'), [
            'symbol' => 'ETHUSDT',
            'mode' => 'normal',
            'margin' => 100,
            'leverage' => 3,
            'trailing_up' => false,
            'trailing_down' => false,
            'matched_trades' => 0,
            'started_at' => now()->subHour()->toIso8601String(),
        ])
        ->assertSessionHasNoErrors();

    $trade = $user->gridTrades()->sole();

    expect($trade->ended_at)->toBeNull()
        ->and($trade->pnl)->toBeNull()
        ->and($trade->range_horizon_days)->toBeNull()
        ->and($trade->matched_profit)->toBeNull()
        ->and($trade->lower_price)->toBeNull();
});

it('rejects invalid trades', function (array $overrides, string $field) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('grid-trades.store'), journalPayload($overrides))
        ->assertSessionHasErrors($field);

    expect(GridTrade::query()->count())->toBe(0);
})->with([
    'ended before started' => [['ended_at' => '2026-10-19T08:00:00.000Z'], 'ended_at'],
    'started in the future' => [['started_at' => now()->addDay()->toIso8601String(), 'ended_at' => null], 'started_at'],
    'upper limit below lower limit' => [['upper_price' => '50000'], 'upper_price'],
    'negative matched trades' => [['matched_trades' => -1], 'matched_trades'],
    'missing margin' => [['margin' => ''], 'margin'],
    'unknown bot mode' => [['mode' => 'hedge'], 'mode'],
    'invalid symbol' => [['symbol' => 'BTC/USDT'], 'symbol'],
    'missing trailing flag' => [['trailing_up' => null], 'trailing_up'],
    'matched profit not a number' => [['matched_profit' => 'lots'], 'matched_profit'],
    'matched profit with too many decimals' => [['matched_profit' => '1.234'], 'matched_profit'],
    'range horizon not offered' => [['range_horizon_days' => 4], 'range_horizon_days'],
]);

it('updates the user\'s own trade', function () {
    $user = User::factory()->create();
    $trade = GridTrade::factory()->for($user)->running()->create();

    $this->actingAs($user)
        ->put(route('grid-trades.update', $trade), journalPayload(['matched_trades' => 52, 'pnl' => '18.75']))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('grid-trades.index'));

    $trade->refresh();

    expect($trade->matched_trades)->toBe(52)
        ->and($trade->pnl)->toBe(18.75)
        ->and($trade->ended_at)->not->toBeNull();
});

it('backfills the matched profit of an existing trade and shows it in the journal', function () {
    $user = User::factory()->create();
    $trade = GridTrade::factory()->for($user)->create();

    $this->actingAs($user)
        ->put(route('grid-trades.update', $trade), journalPayload(['matched_profit' => '21.40']))
        ->assertSessionHasNoErrors();

    $this->actingAs($user)
        ->get(route('grid-trades.index'))
        ->assertInertia(fn (Assert $page) => $page->where('trades.0.matched_profit', 21.4));
});

it('forbids updating another user\'s trade', function () {
    $trade = GridTrade::factory()->create(['matched_trades' => 5]);

    $this->actingAs(User::factory()->create())
        ->put(route('grid-trades.update', $trade), journalPayload())
        ->assertForbidden();

    expect($trade->refresh()->matched_trades)->toBe(5);
});

it('deletes the user\'s own trade', function () {
    $user = User::factory()->create();
    $trade = GridTrade::factory()->for($user)->create();

    $this->actingAs($user)
        ->delete(route('grid-trades.destroy', $trade))
        ->assertRedirect(route('grid-trades.index'));

    $this->assertModelMissing($trade);
});

it('forbids deleting another user\'s trade', function () {
    $trade = GridTrade::factory()->create();

    $this->actingAs(User::factory()->create())
        ->delete(route('grid-trades.destroy', $trade))
        ->assertForbidden();

    $this->assertModelExists($trade);
});
