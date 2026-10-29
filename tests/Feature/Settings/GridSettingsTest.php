<?php

use App\Jobs\RunGridScan;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

test('grid settings page is displayed with the current spacing multiplier', function () {
    $user = User::factory()->create();
    AppSetting::setSpacingMultiplier(0.4);

    $response = $this
        ->actingAs($user)
        ->get(route('grid-settings.edit'));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('spacingMultiplier', 0.4)
            ->where('defaultSpacingMultiplier', 0.6)
            ->where('allowTrailing', true)
            ->where('rangeHorizonDays', 7)
            ->where('rangeHorizonOptions', [3, 5, 7]));
});

test('spacing multiplier falls back to the configured default', function () {
    expect(AppSetting::spacingMultiplier())->toBe(0.6);
});

test('total margin can be updated without rescanning', function () {
    Queue::fake([RunGridScan::class]);
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('grid-settings.update'), [
            'total_margin' => '2500.50',
            'spacing_multiplier' => '0.6',
            'allow_trailing' => '1',
            'range_horizon_days' => '7',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('grid-settings.edit'));

    expect($user->refresh()->total_margin)->toBe(2500.5);
    Queue::assertNotPushed(RunGridScan::class);
});

test('changing the spacing multiplier saves it and queues a rescan', function () {
    Queue::fake([RunGridScan::class]);
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('grid-settings.update'), [
            'total_margin' => '1000',
            'spacing_multiplier' => '0.35',
            'allow_trailing' => '1',
            'range_horizon_days' => '7',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('grid-settings.edit'));

    expect(AppSetting::spacingMultiplier())->toBe(0.35);
    Queue::assertPushed(RunGridScan::class);
});

test('turning off trailing suggestions saves it and queues a rescan', function () {
    Queue::fake([RunGridScan::class]);
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('grid-settings.update'), [
            'total_margin' => '1000',
            'spacing_multiplier' => '0.6',
            'allow_trailing' => '0',
            'range_horizon_days' => '7',
        ]);

    $response->assertSessionHasNoErrors();

    expect(AppSetting::allowsTrailing())->toBeFalse()
        ->and(AppSetting::spacingMultiplier())->toBe(0.6);
    Queue::assertPushed(RunGridScan::class);
});

test('allow trailing must be a boolean', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('grid-settings.update'), [
            'total_margin' => '1000',
            'spacing_multiplier' => '0.6',
            'allow_trailing' => 'maybe',
            'range_horizon_days' => '7',
        ])
        ->assertSessionHasErrors('allow_trailing');

    expect(AppSetting::allowsTrailing())->toBeTrue();
});

test('changing the range horizon saves it and queues a rescan', function () {
    Queue::fake([RunGridScan::class]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('grid-settings.update'), [
            'total_margin' => '1000',
            'spacing_multiplier' => '0.6',
            'allow_trailing' => '1',
            'range_horizon_days' => '3',
        ])
        ->assertSessionHasNoErrors();

    expect(AppSetting::rangeHorizonDays())->toBe(3);
    Queue::assertPushed(RunGridScan::class);
});

test('range horizon must be one of the offered choices', function (mixed $days) {
    Queue::fake([RunGridScan::class]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('grid-settings.update'), [
            'total_margin' => '1000',
            'spacing_multiplier' => '0.6',
            'allow_trailing' => '1',
            'range_horizon_days' => $days,
        ])
        ->assertSessionHasErrors('range_horizon_days');

    expect(AppSetting::rangeHorizonDays())->toBe(7);
    Queue::assertNotPushed(RunGridScan::class);
})->with([
    'missing' => [''],
    'not offered' => ['4'],
    'not a number' => ['week'],
]);

test('total margin must be a valid amount', function (mixed $totalMargin) {
    $user = User::factory()->create(['total_margin' => 1000]);

    $response = $this
        ->actingAs($user)
        ->from(route('grid-settings.edit'))
        ->patch(route('grid-settings.update'), [
            'total_margin' => $totalMargin,
            'spacing_multiplier' => '0.6',
            'allow_trailing' => '1',
            'range_horizon_days' => '7',
        ]);

    $response
        ->assertSessionHasErrors('total_margin')
        ->assertRedirect(route('grid-settings.edit'));

    expect($user->refresh()->total_margin)->toBe(1000.0);
})->with([
    'missing' => [''],
    'not a number' => ['lots'],
    'below minimum' => ['5'],
    'too many decimals' => ['100.123'],
]);

test('spacing multiplier must be within range', function (mixed $spacingMultiplier) {
    Queue::fake([RunGridScan::class]);
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('grid-settings.edit'))
        ->patch(route('grid-settings.update'), [
            'total_margin' => '1000',
            'spacing_multiplier' => $spacingMultiplier,
            'allow_trailing' => '1',
            'range_horizon_days' => '7',
        ]);

    $response->assertSessionHasErrors('spacing_multiplier');

    expect(AppSetting::spacingMultiplier())->toBe(0.6);
    Queue::assertNotPushed(RunGridScan::class);
})->with([
    'missing' => [''],
    'below minimum' => ['0.1'],
    'above maximum' => ['2'],
    'too many decimals' => ['0.555'],
]);
