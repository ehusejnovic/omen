<?php

use App\Enums\GridGrade;
use App\Enums\GridMode;
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
            ->where('rangeHorizonOptions', [3, 5, 7])
            ->where('minimumGrade', 'C')
            ->where('modeFilter', 'all'));
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
            'minimum_grade' => 'C',
            'mode_filter' => 'all',
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
            'minimum_grade' => 'C',
            'mode_filter' => 'all',
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
            'minimum_grade' => 'C',
            'mode_filter' => 'all',
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
            'minimum_grade' => 'C',
            'mode_filter' => 'all',
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
            'minimum_grade' => 'C',
            'mode_filter' => 'all',
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
            'minimum_grade' => 'C',
            'mode_filter' => 'all',
        ])
        ->assertSessionHasErrors('range_horizon_days');

    expect(AppSetting::rangeHorizonDays())->toBe(7);
    Queue::assertNotPushed(RunGridScan::class);
})->with([
    'missing' => [''],
    'not offered' => ['4'],
    'not a number' => ['week'],
]);

test('changing which setups scans keep saves it and queues a rescan', function () {
    Queue::fake([RunGridScan::class]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('grid-settings.update'), [
            'total_margin' => '1000',
            'spacing_multiplier' => '0.6',
            'allow_trailing' => '1',
            'range_horizon_days' => '7',
            'minimum_grade' => 'B',
            'mode_filter' => 'short',
        ])
        ->assertSessionHasNoErrors();

    expect(AppSetting::minimumGrade())->toBe(GridGrade::B)
        ->and(AppSetting::modeFilter())->toBe(GridMode::Short);
    Queue::assertPushed(RunGridScan::class);
});

test('setting bot types back to all clears the filter', function () {
    Queue::fake([RunGridScan::class]);
    AppSetting::setModeFilter(GridMode::Long);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('grid-settings.update'), [
            'total_margin' => '1000',
            'spacing_multiplier' => '0.6',
            'allow_trailing' => '1',
            'range_horizon_days' => '7',
            'minimum_grade' => 'C',
            'mode_filter' => 'all',
        ])
        ->assertSessionHasNoErrors();

    expect(AppSetting::modeFilter())->toBeNull();
    Queue::assertPushed(RunGridScan::class);
});

test('scan result filters must be offered choices', function (string $field, string $value) {
    Queue::fake([RunGridScan::class]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('grid-settings.update'), [
            'total_margin' => '1000',
            'spacing_multiplier' => '0.6',
            'allow_trailing' => '1',
            'range_horizon_days' => '7',
            'minimum_grade' => 'C',
            'mode_filter' => 'all',
            $field => $value,
        ])
        ->assertSessionHasErrors($field);

    Queue::assertNotPushed(RunGridScan::class);
})->with([
    'unknown grade' => ['minimum_grade', 'D'],
    'unknown bot type' => ['mode_filter', 'hedge'],
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
            'minimum_grade' => 'C',
            'mode_filter' => 'all',
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
            'minimum_grade' => 'C',
            'mode_filter' => 'all',
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
