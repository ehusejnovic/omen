<?php

namespace App\Http\Controllers;

use App\Http\Requests\GridTradeRequest;
use App\Http\Resources\GridTradeResource;
use App\Models\GridTrade;
use App\Services\Grid\PortfolioExposure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class GridTradeController extends Controller
{
    /**
     * Show the user's trade journal with summary statistics.
     */
    public function index(Request $request, PortfolioExposure $portfolioExposure): Response
    {
        $trades = $request->user()->gridTrades()->latest('started_at')->latest('id')->get();
        $closedTrades = $trades->whereNotNull('ended_at');
        $tradesWithPnl = $closedTrades->whereNotNull('pnl');

        return Inertia::render('journal', [
            'trades' => GridTradeResource::collection($trades)->resolve($request),
            'exposure' => $portfolioExposure->forRunningTrades($trades, $request->user()->total_margin),
            'maxPortfolioShockLossPct' => (float) config('grid.max_portfolio_shock_loss') * 100,
            'summary' => [
                'total_trades' => $trades->count(),
                'running_trades' => $trades->count() - $closedTrades->count(),
                'total_pnl' => round($tradesWithPnl->sum('pnl'), 2),
                'win_rate_pct' => $tradesWithPnl->isEmpty()
                    ? null
                    : round($tradesWithPnl->where('pnl', '>', 0)->count() / $tradesWithPnl->count() * 100, 1),
                'total_matched_trades' => $trades->sum('matched_trades'),
                'average_duration_seconds' => $closedTrades->isEmpty()
                    ? null
                    : (int) round($closedTrades->avg(fn (GridTrade $trade): int => $trade->durationInSeconds())),
            ],
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
