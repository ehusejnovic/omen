<?php

namespace App\Http\Controllers;

use App\Http\Resources\GridTradeResource;
use App\Services\Grid\PortfolioExposure;
use App\Services\Grid\TradeAnalytics;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    /**
     * Show performance analytics over the user's journaled trades.
     */
    public function index(Request $request, TradeAnalytics $analytics, PortfolioExposure $portfolioExposure): Response
    {
        $trades = $request->user()->gridTrades()->latest('started_at')->latest('id')->get();

        return Inertia::render('analytics', [
            'trades' => GridTradeResource::collection($trades)->resolve($request),
            'summary' => $analytics->summary($trades),
            'pnlBreakdown' => $analytics->pnlBreakdown($trades),
            'breakdowns' => $analytics->breakdowns($trades),
            'bySymbol' => $analytics->bySymbol($trades),
            'exposure' => $portfolioExposure->forRunningTrades($trades, $request->user()->total_margin),
            'maxPortfolioShockLossPct' => (float) config('grid.max_portfolio_shock_loss') * 100,
        ]);
    }
}
