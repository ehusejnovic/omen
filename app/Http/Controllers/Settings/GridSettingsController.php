<?php

namespace App\Http\Controllers\Settings;

use App\Enums\GridGrade;
use App\Enums\GridMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\GridSettingsUpdateRequest;
use App\Jobs\RunGridScan;
use App\Models\AppSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GridSettingsController extends Controller
{
    /**
     * Show the grid bot settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/grid', [
            'totalMargin' => $request->user()->total_margin,
            'spacingMultiplier' => AppSetting::spacingMultiplier(),
            'defaultSpacingMultiplier' => (float) config('grid.spacing_multiplier'),
            'allowTrailing' => AppSetting::allowsTrailing(),
            'rangeHorizonDays' => AppSetting::rangeHorizonDays(),
            'rangeHorizonOptions' => config('grid.range_horizons'),
            'minimumGrade' => AppSetting::minimumGrade(),
            'modeFilter' => AppSetting::modeFilter()->value ?? 'all',
        ]);
    }

    /**
     * Update the user's margin and the app-wide scan settings, rescanning when a scan setting changes.
     */
    public function update(GridSettingsUpdateRequest $request): RedirectResponse
    {
        $request->user()->update($request->safe()->only('total_margin'));

        $spacingMultiplier = round((float) $request->validated('spacing_multiplier'), 2);
        $allowTrailing = $request->boolean('allow_trailing');
        $rangeHorizonDays = $request->integer('range_horizon_days');
        $minimumGrade = $request->enum('minimum_grade', GridGrade::class);
        $modeFilter = GridMode::tryFrom($request->string('mode_filter')->value());
        $scanSettingsChanged = $spacingMultiplier !== AppSetting::spacingMultiplier()
            || $allowTrailing !== AppSetting::allowsTrailing()
            || $rangeHorizonDays !== AppSetting::rangeHorizonDays()
            || $minimumGrade !== AppSetting::minimumGrade()
            || $modeFilter !== AppSetting::modeFilter();

        if ($scanSettingsChanged) {
            AppSetting::setSpacingMultiplier($spacingMultiplier);
            AppSetting::setAllowsTrailing($allowTrailing);
            AppSetting::setRangeHorizonDays($rangeHorizonDays);
            AppSetting::setMinimumGrade($minimumGrade);
            AppSetting::setModeFilter($modeFilter);
            RunGridScan::dispatch();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $scanSettingsChanged
            ? __('Grid settings updated. Rescanning with the new settings.')
            : __('Grid settings updated.')]);

        return to_route('grid-settings.edit');
    }
}
