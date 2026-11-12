<?php

namespace App\Services\Grid;

use App\Enums\GridGrade;
use App\Enums\GridMode;
use App\Models\GridTrade;
use Closure;
use Illuminate\Support\Collection;

/**
 * Performance statistics over the user's journaled grid trades. Results only count finished trades with a PnL.
 */
class TradeAnalytics
{
    /**
     * Duration groups as [upper bound in hours, label]; the last group has no upper bound.
     *
     * @var list<array{int|null, string}>
     */
    private const array DurationBuckets = [[6, 'Under 6h'], [12, '6–12h'], [24, '12–24h'], [48, '1–2 days'], [null, 'Over 2 days']];

    /**
     * @param  Collection<int, GridTrade>  $trades
     * @return array{total_trades: int, running_trades: int, closed_trades: int, total_pnl: float, wins: int, losses: int, win_rate_pct: float|null, gross_profit: float, gross_loss: float, profit_factor: float|null, average_win: float|null, average_loss: float|null, expectancy: float|null, average_roi_pct: float|null, daily_return_pct: float|null, max_drawdown: float, longest_win_streak: int, longest_loss_streak: int, best_trade: array{id: int, symbol: string, pnl: float, roi_pct: float}|null, worst_trade: array{id: int, symbol: string, pnl: float, roi_pct: float}|null, total_matched_trades: int, average_duration_seconds: int|null}
     */
    public function summary(Collection $trades): array
    {
        $closed = $this->closed($trades);
        $wins = $closed->where('pnl', '>', 0);
        $losses = $closed->where('pnl', '<', 0);
        $grossProfit = $wins->sum('pnl');
        $grossLoss = abs($losses->sum('pnl'));
        $marginDays = $closed->sum(fn (GridTrade $trade): float => $trade->margin * $trade->durationInSeconds() / 86400);
        $streaks = $this->streaks($closed);

        return [
            'total_trades' => $trades->count(),
            'running_trades' => $trades->whereNull('ended_at')->count(),
            'closed_trades' => $closed->count(),
            'total_pnl' => round($closed->sum('pnl'), 2),
            'wins' => $wins->count(),
            'losses' => $losses->count(),
            'win_rate_pct' => $this->winRate($closed),
            'gross_profit' => round($grossProfit, 2),
            'gross_loss' => round($grossLoss, 2),
            'profit_factor' => $this->profitFactor($closed),
            'average_win' => $wins->isEmpty() ? null : round($wins->avg('pnl'), 2),
            'average_loss' => $losses->isEmpty() ? null : round($losses->avg('pnl'), 2),
            'expectancy' => $closed->isEmpty() ? null : round($closed->avg('pnl'), 2),
            'average_roi_pct' => $this->averageRoi($closed),
            'daily_return_pct' => $marginDays > 0 ? round($closed->sum('pnl') / $marginDays * 100, 2) : null,
            'max_drawdown' => $this->maxDrawdown($closed),
            'longest_win_streak' => $streaks['wins'],
            'longest_loss_streak' => $streaks['losses'],
            'best_trade' => $this->tradeSummary($closed->sortByDesc('pnl')->first()),
            'worst_trade' => $this->tradeSummary($closed->sortBy('pnl')->first()),
            'total_matched_trades' => (int) $trades->sum('matched_trades'),
            'average_duration_seconds' => $closed->isEmpty()
                ? null
                : (int) round($closed->avg(fn (GridTrade $trade): int => $trade->durationInSeconds())),
        ];
    }

    /**
     * Final PnL of every finished trade, split into what the grids earned, the result of the position held
     * at close (PnL minus matched profit, fees and funding included), and the PnL of trades that have no
     * matched profit recorded and so cannot be split. The three parts add up to the final PnL.
     *
     * @param  Collection<int, GridTrade>  $trades
     * @return array{trades: int, profitable: int, pnl: float, matched_profit: float, position_pnl: float, unsplit_trades: int, unsplit_pnl: float}|null
     */
    public function pnlBreakdown(Collection $trades): ?array
    {
        $closed = $this->closed($trades);
        [$measured, $unsplit] = $closed->partition(fn (GridTrade $trade): bool => $trade->matched_profit !== null);

        if ($measured->isEmpty()) {
            return null;
        }

        return [
            'trades' => $closed->count(),
            'profitable' => $closed->where('pnl', '>', 0)->count(),
            'pnl' => round($closed->sum('pnl'), 2),
            'matched_profit' => round($measured->sum('matched_profit'), 2),
            'position_pnl' => round($measured->sum(fn (GridTrade $trade): float => $trade->pnl - $trade->matched_profit), 2),
            'unsplit_trades' => $unsplit->count(),
            'unsplit_pnl' => round($unsplit->sum('pnl'), 2),
        ];
    }

    /**
     * Results grouped by each trade attribute worth comparing.
     *
     * @param  Collection<int, GridTrade>  $trades
     * @return array<string, list<array{label: string, trades: int, closed_with_pnl: int, total_pnl: float, win_rate_pct: float|null, average_roi_pct: float|null, profit_factor: float|null, matched_per_day: float|null}>>
     */
    public function breakdowns(Collection $trades): array
    {
        $grades = GridGrade::cases();
        $modes = GridMode::cases();

        return [
            'grade' => $this->breakdown(
                $trades,
                fn (GridTrade $trade): int => $trade->suggested_grade === null ? PHP_INT_MAX : (int) array_search($trade->suggested_grade, $grades, true),
                fn (GridTrade $trade): string => $trade->suggested_grade->value ?? 'Not set',
            ),
            'leverage' => $this->breakdown(
                $trades,
                fn (GridTrade $trade): int => $trade->leverage,
                fn (GridTrade $trade): string => "{$trade->leverage}x",
            ),
            'horizon' => $this->breakdown(
                $trades,
                fn (GridTrade $trade): int => $trade->range_horizon_days ?? PHP_INT_MAX,
                fn (GridTrade $trade): string => $trade->range_horizon_days === null ? 'Not set' : "{$trade->range_horizon_days} days",
            ),
            'duration' => $this->breakdown(
                $trades->whereNotNull('ended_at'),
                fn (GridTrade $trade): int => $this->durationBucket($trade),
                fn (GridTrade $trade): string => self::DurationBuckets[$this->durationBucket($trade)][1],
            ),
            'mode' => $this->breakdown(
                $trades,
                fn (GridTrade $trade): int => (int) array_search($trade->mode, $modes, true),
                fn (GridTrade $trade): string => ucfirst($trade->mode->value),
            ),
        ];
    }

    /**
     * Results per symbol, most profitable first.
     *
     * @param  Collection<int, GridTrade>  $trades
     * @return list<array{label: string, trades: int, closed_with_pnl: int, total_pnl: float, win_rate_pct: float|null, average_roi_pct: float|null, profit_factor: float|null, matched_per_day: float|null}>
     */
    public function bySymbol(Collection $trades): array
    {
        return array_values($trades
            ->groupBy('symbol')
            ->map(fn (Collection $group, string $symbol): array => $this->row($symbol, $group))
            ->sortBy([['total_pnl', 'desc'], ['label', 'asc']])
            ->values()
            ->all());
    }

    /**
     * @param  Collection<int, GridTrade>  $trades
     * @param  Closure(GridTrade): int  $rank
     * @param  Closure(GridTrade): string  $label
     * @return list<array{label: string, trades: int, closed_with_pnl: int, total_pnl: float, win_rate_pct: float|null, average_roi_pct: float|null, profit_factor: float|null, matched_per_day: float|null}>
     */
    private function breakdown(Collection $trades, Closure $rank, Closure $label): array
    {
        return array_values($trades
            ->groupBy($rank)
            ->sortKeys()
            ->map(fn (Collection $group): array => $this->row($label($group->first()), $group))
            ->all());
    }

    /**
     * Index of the duration group a finished trade falls in.
     */
    private function durationBucket(GridTrade $trade): int
    {
        foreach (self::DurationBuckets as $index => [$hours]) {
            if ($hours === null || $trade->durationInSeconds() < $hours * 3600) {
                return $index;
            }
        }

        return array_key_last(self::DurationBuckets);
    }

    /**
     * @param  Collection<int, GridTrade>  $group
     * @return array{label: string, trades: int, closed_with_pnl: int, total_pnl: float, win_rate_pct: float|null, average_roi_pct: float|null, profit_factor: float|null, matched_per_day: float|null}
     */
    private function row(string $label, Collection $group): array
    {
        $closed = $this->closed($group);
        $days = $group->sum(fn (GridTrade $trade): int => $trade->durationInSeconds()) / 86400;

        return [
            'label' => $label,
            'trades' => $group->count(),
            'closed_with_pnl' => $closed->count(),
            'total_pnl' => round($closed->sum('pnl'), 2),
            'win_rate_pct' => $this->winRate($closed),
            'average_roi_pct' => $this->averageRoi($closed),
            'profit_factor' => $this->profitFactor($closed),
            'matched_per_day' => $days > 0 ? round($group->sum('matched_trades') / $days, 1) : null,
        ];
    }

    /**
     * Finished trades with a PnL, in the order they ended.
     *
     * @param  Collection<int, GridTrade>  $trades
     * @return Collection<int, GridTrade>
     */
    private function closed(Collection $trades): Collection
    {
        return $trades
            ->filter(fn (GridTrade $trade): bool => $trade->ended_at !== null && $trade->pnl !== null)
            ->sortBy([['ended_at', 'asc'], ['id', 'asc']])
            ->values();
    }

    /**
     * @param  Collection<int, GridTrade>  $closed
     */
    private function winRate(Collection $closed): ?float
    {
        return $closed->isEmpty() ? null : round($closed->where('pnl', '>', 0)->count() / $closed->count() * 100, 1);
    }

    /**
     * @param  Collection<int, GridTrade>  $closed
     */
    private function averageRoi(Collection $closed): ?float
    {
        return $closed->isEmpty() ? null : round($closed->avg(fn (GridTrade $trade): float => $trade->pnl / $trade->margin * 100), 2);
    }

    /**
     * Gross profit divided by gross loss, or null without any losing trade.
     *
     * @param  Collection<int, GridTrade>  $closed
     */
    private function profitFactor(Collection $closed): ?float
    {
        $grossLoss = abs($closed->where('pnl', '<', 0)->sum('pnl'));

        return $grossLoss > 0 ? round($closed->where('pnl', '>', 0)->sum('pnl') / $grossLoss, 2) : null;
    }

    /**
     * Largest fall of cumulative PnL from a previous peak, as a negative amount.
     *
     * @param  Collection<int, GridTrade>  $closed
     */
    private function maxDrawdown(Collection $closed): float
    {
        $cumulative = 0.0;
        $peak = 0.0;
        $drawdown = 0.0;

        foreach ($closed as $trade) {
            $cumulative += $trade->pnl;
            $peak = max($peak, $cumulative);
            $drawdown = min($drawdown, $cumulative - $peak);
        }

        return round($drawdown, 2);
    }

    /**
     * Longest runs of consecutive winning and losing trades.
     *
     * @param  Collection<int, GridTrade>  $closed
     * @return array{wins: int, losses: int}
     */
    private function streaks(Collection $closed): array
    {
        $longest = ['wins' => 0, 'losses' => 0];
        $current = ['wins' => 0, 'losses' => 0];

        foreach ($closed as $trade) {
            $current['wins'] = $trade->pnl > 0 ? $current['wins'] + 1 : 0;
            $current['losses'] = $trade->pnl < 0 ? $current['losses'] + 1 : 0;
            $longest = ['wins' => max($longest['wins'], $current['wins']), 'losses' => max($longest['losses'], $current['losses'])];
        }

        return $longest;
    }

    /**
     * @return array{id: int, symbol: string, pnl: float, roi_pct: float}|null
     */
    private function tradeSummary(?GridTrade $trade): ?array
    {
        return $trade === null ? null : [
            'id' => $trade->id,
            'symbol' => $trade->symbol,
            'pnl' => round($trade->pnl, 2),
            'roi_pct' => round($trade->pnl / $trade->margin * 100, 2),
        ];
    }
}
