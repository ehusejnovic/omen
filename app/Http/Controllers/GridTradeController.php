<?php

namespace App\Http\Controllers;

use App\Http\Requests\GridTradeRequest;
use App\Http\Resources\GridTradeResource;
use App\Models\GridTrade;
use App\Services\Grid\PortfolioExposure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
            'byHorizon' => $this->resultsByHorizon($trades),
            'rangeHorizonOptions' => config('grid.range_horizons'),
        ]);
    }

    /**
     * Compare results per range horizon, with trades that have no horizon grouped last.
     *
     * @param  Collection<int, GridTrade>  $trades
     * @return list<array{range_horizon_days: int|null, trades: int, closed_with_pnl: int, total_pnl: float, win_rate_pct: float|null, average_roi_pct: float|null, matched_per_day: float|null}>
     */
    private function resultsByHorizon(Collection $trades): array
    {
        return array_values($trades
            ->groupBy(fn (GridTrade $trade): string => (string) $trade->range_horizon_days)
            ->sortKeysUsing(fn (string $a, string $b): int => ($a === '' ? PHP_INT_MAX : (int) $a) <=> ($b === '' ? PHP_INT_MAX : (int) $b))
            ->map(function (Collection $group, string $horizon): array {
                $withPnl = $group->whereNotNull('ended_at')->whereNotNull('pnl');
                $days = $group->sum(fn (GridTrade $trade): int => $trade->durationInSeconds()) / 86400;

                return [
                    'range_horizon_days' => $horizon === '' ? null : (int) $horizon,
                    'trades' => $group->count(),
                    'closed_with_pnl' => $withPnl->count(),
                    'total_pnl' => round($withPnl->sum('pnl'), 2),
                    'win_rate_pct' => $withPnl->isEmpty() ? null : round($withPnl->where('pnl', '>', 0)->count() / $withPnl->count() * 100, 1),
                    'average_roi_pct' => $withPnl->isEmpty() ? null : round($withPnl->avg(fn (GridTrade $trade): float => $trade->pnl / $trade->margin * 100), 2),
                    'matched_per_day' => $days > 0 ? round($group->sum('matched_trades') / $days, 1) : null,
                ];
            })
            ->all());
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
