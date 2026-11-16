<?php

use App\Enums\GridGrade;
use App\Enums\GridMode;
use App\Enums\TradeSource;
use App\Models\FuturesSymbol;
use App\Models\GridTrade;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
    FuturesSymbol::factory()->create(['symbol' => 'BTCUSDT', 'base_asset' => 'BTC']);
    FuturesSymbol::factory()->create(['symbol' => 'ETHUSDT', 'base_asset' => 'ETH']);
});

function fakeBinanceListing(): void
{
    Http::fake(['fapi.binance.com/fapi/v1/exchangeInfo' => Http::response(['symbols' => [
        listedContract('BTCUSDT', 'BTC'),
        listedContract('ETHUSDT', 'ETH'),
        listedContract('NEWUSDT', 'NEW'),
    ]])]);
}

/**
 * @return array<string, mixed>
 */
function listedContract(string $symbol, string $baseAsset): array
{
    return [
        'symbol' => $symbol,
        'baseAsset' => $baseAsset,
        'quoteAsset' => 'USDT',
        'contractType' => 'PERPETUAL',
        'status' => 'TRADING',
        'filters' => [['filterType' => 'PRICE_FILTER', 'tickSize' => '0.01']],
    ];
}

/**
 * @return array<string, mixed>
 */
function journalPayload(array $overrides = []): array
{
    return [
        'symbol' => ' btcusdt ',
        'mode' => 'long',
        'source' => 'scanner',
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

it('offers only the Binance symbols that are still trading', function () {
    FuturesSymbol::factory()->delisted()->create(['symbol' => 'GONEUSDT']);

    $this->actingAs(User::factory()->create())
        ->get(route('grid-trades.index'))
        ->assertInertia(fn (Assert $page) => $page->where('symbols', ['BTCUSDT', 'ETHUSDT']));
});

it('journals a trade for the signed-in user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('grid-trades.store'), journalPayload())
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('grid-trades.index'));

    $trade = $user->gridTrades()->sole();

    expect($trade->symbol)->toBe('BTCUSDT')
        ->and($trade->futuresSymbol->base_asset)->toBe('BTC')
        ->and($trade->mode)->toBe(GridMode::Long)
        ->and($trade->source)->toBe(TradeSource::Scanner)
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
            'source' => 'manual',
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

it('loads the Binance symbols when none are stored yet', function () {
    fakeBinanceListing();
    FuturesSymbol::query()->delete();

    $this->actingAs(User::factory()->create())
        ->get(route('grid-trades.index'))
        ->assertInertia(fn (Assert $page) => $page->where('symbols', ['BTCUSDT', 'ETHUSDT', 'NEWUSDT']));
});

it('still opens the journal when Binance is unreachable and no symbols are stored', function () {
    Sleep::fake();
    Http::fake(['fapi.binance.com/*' => Http::response(['code' => -1000], 503)]);
    FuturesSymbol::query()->delete();

    $this->actingAs(User::factory()->create())
        ->get(route('grid-trades.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('symbols', []));
});

it('accepts a symbol Binance listed after the last sync', function () {
    fakeBinanceListing();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('grid-trades.store'), journalPayload(['symbol' => 'newusdt']))
        ->assertSessionHasNoErrors();

    expect($user->gridTrades()->sole()->symbol)->toBe('NEWUSDT');
});

it('journals a manual trade the scanner never suggested', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('grid-trades.store'), journalPayload(['source' => 'manual', 'suggested_grade' => null]))
        ->assertSessionHasNoErrors();

    $trade = $user->gridTrades()->sole();

    expect($trade->source)->toBe(TradeSource::Manual)
        ->and($trade->suggested_grade)->toBeNull();

    $this->actingAs($user)
        ->get(route('grid-trades.index'))
        ->assertInertia(fn (Assert $page) => $page->where('trades.0.source', 'manual'));
});

it('lists the trades journaled on a Binance symbol', function () {
    $trade = GridTrade::factory()->create(['symbol' => 'BTCUSDT']);
    GridTrade::factory()->create(['symbol' => 'ETHUSDT']);

    expect(FuturesSymbol::query()->find('BTCUSDT')->gridTrades->modelKeys())->toBe([$trade->id]);
});

it('rejects invalid trades', function (array $overrides, string $field) {
    fakeBinanceListing();
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
    'symbol not listed on Binance' => [['symbol' => 'NOPEUSDT'], 'symbol'],
    'missing source' => [['source' => null], 'source'],
    'unknown source' => [['source' => 'copy'], 'source'],
    'manual trade with a suggested grade' => [['source' => 'manual'], 'suggested_grade'],
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
