<?php

namespace App\Http\Controllers;

use App\Http\Requests\GridTradeRequest;
use App\Http\Resources\GridTradeResource;
use App\Models\GridTrade;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class GridTradeController extends Controller
{
    /**
     * Show the user's trade journal.
     */
    public function index(Request $request): Response
    {
        $trades = $request->user()->gridTrades()->latest('started_at')->latest('id')->get();

        return Inertia::render('journal', [
            'trades' => GridTradeResource::collection($trades)->resolve($request),
            'rangeHorizonOptions' => config('grid.range_horizons'),
        ]);
    }

    /**
     * Journal a new trade.
     */
    public function store(GridTradeRequest $request): RedirectResponse
    {
        $request->user()->gridTrades()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Trade journaled.')]);

        return to_route('grid-trades.index');
    }

    /**
     * Update a journaled trade.
     */
    public function update(GridTradeRequest $request, GridTrade $gridTrade): RedirectResponse
    {
        Gate::authorize('update', $gridTrade);

        $gridTrade->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Trade updated.')]);

        return to_route('grid-trades.index');
    }

    /**
     * Remove a journaled trade.
     */
    public function destroy(GridTrade $gridTrade): RedirectResponse
    {
        Gate::authorize('delete', $gridTrade);

        $gridTrade->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Trade deleted.')]);

        return to_route('grid-trades.index');
    }
}
