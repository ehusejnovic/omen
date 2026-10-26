<?php

namespace App\Http\Controllers;

use App\Http\Resources\GridSuggestionResource;
use App\Jobs\RunGridScan;
use App\Models\GridScan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GridScannerController extends Controller
{
    /**
     * Show the suggestions from the latest completed scan.
     */
    public function index(Request $request): Response
    {
        $latestScan = GridScan::query()->where('status', GridScan::StatusCompleted)->latest('id')->first();
        $suggestions = $latestScan?->suggestions()->orderByDesc('score')->orderBy('symbol')->get() ?? collect();

        return Inertia::render('dashboard', [
            'scan' => $latestScan?->only(['id', 'symbols_scanned', 'suggestions_count', 'spacing_multiplier', 'started_at', 'finished_at']),
            'isScanning' => GridScan::query()->where('status', GridScan::StatusRunning)->where('started_at', '>', now()->subMinutes(15))->exists(),
            'totalMargin' => $request->user()->total_margin,
            'suggestions' => GridSuggestionResource::collection($suggestions)->resolve($request),
        ]);
    }

    /**
     * Queue an immediate scan.
     */
    public function store(): RedirectResponse
    {
        RunGridScan::dispatch();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Scan queued. Results refresh when it finishes.')]);

        return to_route('dashboard');
    }
}
