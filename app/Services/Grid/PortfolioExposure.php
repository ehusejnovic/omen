<?php

namespace App\Services\Grid;

use App\Enums\GridMode;
use App\Models\GridTrade;
use App\Models\SymbolStat;
use Illuminate\Support\Collection;

/**
 * Estimates how the user's running bots would react together to a sudden BTC move.
 */
class PortfolioExposure
{
    /**
     * Net position direction during a sudden BTC drop: Normal bots buy on the way down.
     */
    private const array DownShockSide = ['long' => 1.0, 'normal' => 0.5, 'short' => -1.0];

    /**
     * Net position direction during a sudden BTC rally: Normal bots sell on the way up.
     */
    private const array UpShockSide = ['long' => 1.0, 'normal' => -0.5, 'short' => -1.0];

    /**
     * Summarize BTC exposure across running trades, or return null when nothing is running.
     *
     * @param  Collection<int, GridTrade>  $trades
     * @return array{btc_shock_pct: float|null, net_beta_exposure: float, down_shock_pnl: float, up_shock_pnl: float, worst_shock_pnl: float, worst_shock_pct_of_margin: float|null, exceeds_limit: bool, measured_trades: int, unmeasured_symbols: list<string>, betas: array<int, float>}|null
     */
    public function forRunningTrades(Collection $trades, float $totalMargin): ?array
    {
        $running = $trades->whereNull('ended_at');

        if ($running->isEmpty()) {
            return null;
        }

        $stats = SymbolStat::query()->whereIn('symbol', $running->pluck('symbol')->unique())->get()->keyBy('symbol');
        $btcShock = (float) (SymbolStat::query()->whereKey(BtcSensitivity::Benchmark)->value('btc_shock_pct') ?? $stats->first()->btc_shock_pct ?? 0) / 100;
        $netBetaExposure = 0.0;
        $downShockPnl = 0.0;
        $upShockPnl = 0.0;
        $betas = [];
        $unmeasured = [];

        foreach ($running as $trade) {
            $stat = $stats->get($trade->symbol);

            if ($stat === null) {
                $unmeasured[] = $trade->symbol;

                continue;
            }

            $notional = $trade->margin * $trade->leverage;
            $netBetaExposure += $notional * $stat->beta * match ($trade->mode) {
                GridMode::Long => 1,
                GridMode::Short => -1,
                GridMode::Normal => 0,
            };
            $downShockPnl -= $notional * self::DownShockSide[$trade->mode->value] * $stat->shock_beta_down * $btcShock;
            $upShockPnl += $notional * self::UpShockSide[$trade->mode->value] * $stat->shock_beta_up * $btcShock;
            $betas[$trade->id] = $stat->beta;
        }

        $worstShockPnl = min($downShockPnl, $upShockPnl, 0.0);

        return [
            'btc_shock_pct' => $btcShock > 0 ? round($btcShock * 100, 2) : null,
            'net_beta_exposure' => round($netBetaExposure, 2),
            'down_shock_pnl' => round($downShockPnl, 2),
            'up_shock_pnl' => round($upShockPnl, 2),
            'worst_shock_pnl' => round($worstShockPnl, 2),
            'worst_shock_pct_of_margin' => $totalMargin > 0 ? round(-$worstShockPnl / $totalMargin * 100, 2) : null,
            'exceeds_limit' => -$worstShockPnl > (float) config('grid.max_portfolio_shock_loss') * $totalMargin,
            'measured_trades' => count($betas),
            'unmeasured_symbols' => array_values(array_unique($unmeasured)),
            'betas' => $betas,
        ];
    }
}
