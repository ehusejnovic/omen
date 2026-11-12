import { Head } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import PnlCurve from '@/components/pnl-curve';
import { Card, CardContent } from '@/components/ui/card';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    formatDuration,
    formatPct,
    pnlClass,
    signedUsdt,
} from '@/lib/grid-format';
import { cn } from '@/lib/utils';
import { analytics } from '@/routes';
import type {
    AnalyticsBreakdowns,
    AnalyticsRow,
    AnalyticsSummary,
    GridTrade,
    PnlBreakdown,
    PortfolioExposure,
} from '@/types';

type Dimension = keyof AnalyticsBreakdowns;

const dimensions: { value: Dimension; label: string; column: string }[] = [
    { value: 'grade', label: 'Grade', column: 'Suggested grade' },
    { value: 'leverage', label: 'Leverage', column: 'Leverage' },
    { value: 'horizon', label: 'Range horizon', column: 'Horizon' },
    { value: 'duration', label: 'Duration', column: 'Duration' },
    { value: 'mode', label: 'Bot mode', column: 'Bot' },
];

function StatCard({
    label,
    value,
    hint,
    className,
}: {
    label: string;
    value: ReactNode;
    hint?: ReactNode;
    className?: string;
}) {
    return (
        <Card className="gap-1 py-4">
            <CardContent className="space-y-1 px-4">
                <p className="text-xs text-muted-foreground">{label}</p>
                <p
                    className={cn(
                        'text-xl font-semibold tabular-nums',
                        className,
                    )}
                >
                    {value}
                </p>
                {hint && (
                    <p className="text-xs text-muted-foreground">{hint}</p>
                )}
            </CardContent>
        </Card>
    );
}

function Section({
    title,
    note,
    children,
    className,
    actions,
}: {
    title: string;
    note?: ReactNode;
    children: ReactNode;
    className?: string;
    actions?: ReactNode;
}) {
    return (
        <Card className={cn('gap-3 py-4', className)}>
            <CardContent className="space-y-3 px-4">
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 className="text-sm font-semibold">{title}</h2>
                    {note && (
                        <p className="text-xs text-muted-foreground">{note}</p>
                    )}
                </div>
                {actions}
                {children}
            </CardContent>
        </Card>
    );
}

function orDash(value: number | null, format: (value: number) => string) {
    return value === null ? '–' : format(value);
}

function ResultsTable({
    rows,
    column,
}: {
    rows: AnalyticsRow[];
    column: string;
}) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead className="text-left text-xs text-muted-foreground">
                    <tr className="[&>th]:py-1.5 [&>th]:pr-4 [&>th]:font-medium [&>th]:whitespace-nowrap">
                        <th>{column}</th>
                        <th className="text-right">Trades</th>
                        <th className="text-right">Total PnL</th>
                        <th className="text-right">Win rate</th>
                        <th className="text-right">Avg ROI</th>
                        <th className="text-right">Profit factor</th>
                        <th className="text-right">Matched / day</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-border/60">
                    {rows.map((row) => (
                        <tr
                            key={row.label}
                            className="[&>td]:py-1.5 [&>td]:pr-4 [&>td]:whitespace-nowrap"
                        >
                            <td className="font-medium">{row.label}</td>
                            <td className="text-right tabular-nums">
                                {row.trades}
                                {row.closed_with_pnl !== row.trades && (
                                    <span className="text-xs text-muted-foreground">
                                        {' '}
                                        ({row.closed_with_pnl} closed)
                                    </span>
                                )}
                            </td>
                            <td
                                className={cn(
                                    'text-right tabular-nums',
                                    pnlClass(row.total_pnl),
                                )}
                            >
                                {signedUsdt(row.total_pnl)}
                            </td>
                            <td className="text-right tabular-nums">
                                {orDash(row.win_rate_pct, (value) =>
                                    formatPct(value, 1),
                                )}
                            </td>
                            <td
                                className={cn(
                                    'text-right tabular-nums',
                                    pnlClass(row.average_roi_pct),
                                )}
                            >
                                {orDash(row.average_roi_pct, (value) =>
                                    formatPct(value, 2, true),
                                )}
                            </td>
                            <td className="text-right tabular-nums">
                                {row.profit_factor === null
                                    ? row.closed_with_pnl > 0 &&
                                      row.total_pnl > 0
                                        ? 'No losses'
                                        : '–'
                                    : row.profit_factor.toFixed(2)}
                            </td>
                            <td className="text-right tabular-nums">
                                {row.matched_per_day ?? '–'}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function ExposureCard({
    exposure,
    maxPortfolioShockLossPct,
}: {
    exposure: PortfolioExposure;
    maxPortfolioShockLossPct: number;
}) {
    return (
        <Section
            title="BTC exposure of running bots"
            className={cn(
                exposure.exceeds_limit &&
                    'border-amber-300 dark:border-amber-800',
            )}
            note={
                <>
                    {exposure.measured_trades} running{' '}
                    {exposure.measured_trades === 1 ? 'bot' : 'bots'} measured
                    {exposure.btc_shock_pct !== null &&
                        ` · BTC shock = ${formatPct(exposure.btc_shock_pct, 1)} sudden move`}
                </>
            }
        >
            {exposure.exceeds_limit && (
                <p className="flex items-start gap-2 text-sm text-amber-700 dark:text-amber-400">
                    <TriangleAlert className="mt-0.5 size-4 shrink-0" />A sudden
                    BTC move could cost your running bots about{' '}
                    {formatPct(exposure.worst_shock_pct_of_margin ?? 0, 1)} of
                    your total margin, above the{' '}
                    {formatPct(maxPortfolioShockLossPct, 0)} limit. They mostly
                    move together with BTC, so consider fewer high-beta bots or
                    a Short bot as a partial hedge.
                </p>
            )}

            <div className="grid gap-4 text-sm sm:grid-cols-3">
                <div>
                    <p className="text-xs text-muted-foreground">
                        Net BTC exposure
                    </p>
                    <p className="font-semibold tabular-nums">
                        {signedUsdt(exposure.net_beta_exposure)} USDT
                    </p>
                    <p className="text-xs text-muted-foreground">
                        Long minus Short position size, weighted by beta
                    </p>
                </div>
                <div>
                    <p className="text-xs text-muted-foreground">
                        If BTC suddenly falls
                    </p>
                    <p
                        className={cn(
                            'font-semibold tabular-nums',
                            pnlClass(exposure.down_shock_pnl),
                        )}
                    >
                        {signedUsdt(exposure.down_shock_pnl)} USDT
                    </p>
                </div>
                <div>
                    <p className="text-xs text-muted-foreground">
                        If BTC suddenly rallies
                    </p>
                    <p
                        className={cn(
                            'font-semibold tabular-nums',
                            pnlClass(exposure.up_shock_pnl),
                        )}
                    >
                        {signedUsdt(exposure.up_shock_pnl)} USDT
                    </p>
                </div>
            </div>

            {exposure.unmeasured_symbols.length > 0 && (
                <p className="text-xs text-muted-foreground">
                    Not included (outside the top 100 scanned):{' '}
                    {exposure.unmeasured_symbols.join(', ')}
                </p>
            )}
        </Section>
    );
}

function PnlBreakdownCard({ breakdown }: { breakdown: PnlBreakdown }) {
    const hasUnsplit = breakdown.unsplit_trades > 0;

    return (
        <Section
            title="Where the final PnL comes from"
            note={`All ${breakdown.trades} finished trades`}
        >
            <div
                className={cn(
                    'grid items-end gap-4 text-sm',
                    hasUnsplit
                        ? 'sm:grid-cols-[1.4fr_auto_1fr_auto_1fr_auto_1fr]'
                        : 'sm:grid-cols-[1.4fr_auto_1fr_auto_1fr]',
                )}
            >
                <div>
                    <p className="text-xs text-muted-foreground">Final PnL</p>
                    <p
                        className={cn(
                            'text-2xl font-semibold tabular-nums',
                            pnlClass(breakdown.pnl),
                        )}
                    >
                        {signedUsdt(breakdown.pnl)} USDT
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {breakdown.profitable} of {breakdown.trades} trades
                        finished in profit
                    </p>
                </div>
                <p
                    className="hidden pb-5 text-lg text-muted-foreground sm:block"
                    aria-hidden
                >
                    =
                </p>
                <div>
                    <p className="text-xs text-muted-foreground">Grid profit</p>
                    <p
                        className={cn(
                            'text-lg font-semibold tabular-nums',
                            pnlClass(breakdown.matched_profit),
                        )}
                    >
                        {signedUsdt(breakdown.matched_profit)} USDT
                    </p>
                    <p className="text-xs text-muted-foreground">
                        Matched buy/sell pairs
                    </p>
                </div>
                <p
                    className="hidden pb-5 text-lg text-muted-foreground sm:block"
                    aria-hidden
                >
                    +
                </p>
                <div>
                    <p className="text-xs text-muted-foreground">
                        Position at close
                    </p>
                    <p
                        className={cn(
                            'text-lg font-semibold tabular-nums',
                            pnlClass(breakdown.position_pnl),
                        )}
                    >
                        {signedUsdt(breakdown.position_pnl)} USDT
                    </p>
                    <p className="text-xs text-muted-foreground">
                        Including fees and funding
                    </p>
                </div>
                {hasUnsplit && (
                    <>
                        <p
                            className="hidden pb-5 text-lg text-muted-foreground sm:block"
                            aria-hidden
                        >
                            +
                        </p>
                        <div>
                            <p className="text-xs text-muted-foreground">
                                Not split
                            </p>
                            <p
                                className={cn(
                                    'text-lg font-semibold tabular-nums',
                                    pnlClass(breakdown.unsplit_pnl),
                                )}
                            >
                                {signedUsdt(breakdown.unsplit_pnl)} USDT
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {breakdown.unsplit_trades}{' '}
                                {breakdown.unsplit_trades === 1
                                    ? 'trade'
                                    : 'trades'}{' '}
                                without matched profit recorded
                            </p>
                        </div>
                    </>
                )}
            </div>
            <p className="text-xs text-muted-foreground">
                Position at close is the final PnL minus grid profit: the price
                move on the coins the bot still held when you stopped it, plus
                fees and funding. A grid buys on the way down, so this part is
                usually negative even on a winning bot. The final PnL is the
                result that counts.
                {hasUnsplit &&
                    ' Add the matched profit to the remaining trades in the journal to split them too.'}
            </p>
        </Section>
    );
}

export default function Analytics({
    trades,
    summary,
    pnlBreakdown,
    breakdowns,
    bySymbol,
    exposure,
    maxPortfolioShockLossPct,
}: {
    trades: GridTrade[];
    summary: AnalyticsSummary;
    pnlBreakdown: PnlBreakdown | null;
    breakdowns: AnalyticsBreakdowns;
    bySymbol: AnalyticsRow[];
    exposure: PortfolioExposure | null;
    maxPortfolioShockLossPct: number;
}) {
    const comparable = dimensions.filter(
        (dimension) => breakdowns[dimension.value].length > 1,
    );
    const [dimension, setDimension] = useState<Dimension>(
        comparable[0]?.value ?? 'grade',
    );
    const activeDimension =
        comparable.find((option) => option.value === dimension) ??
        comparable[0];
    const payoffRatio =
        summary.average_win !== null &&
        summary.average_loss !== null &&
        summary.average_loss !== 0
            ? summary.average_win / Math.abs(summary.average_loss)
            : null;

    return (
        <>
            <Head title="Analytics" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div>
                    <h1 className="text-xl font-semibold tracking-tight">
                        Analytics
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Performance of your journaled grid bots. Results count
                        finished trades with a recorded PnL.
                    </p>
                </div>

                {exposure && (
                    <ExposureCard
                        exposure={exposure}
                        maxPortfolioShockLossPct={maxPortfolioShockLossPct}
                    />
                )}

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                    <StatCard
                        label="Total PnL"
                        value={`${signedUsdt(summary.total_pnl)} USDT`}
                        className={pnlClass(summary.total_pnl)}
                        hint={`${summary.closed_trades} finished · ${summary.running_trades} running`}
                    />
                    <StatCard
                        label="Win rate"
                        value={orDash(summary.win_rate_pct, (value) =>
                            formatPct(value, 1),
                        )}
                        hint={`${summary.wins} wins · ${summary.losses} losses`}
                    />
                    <StatCard
                        label="Profit factor"
                        value={orDash(summary.profit_factor, (value) =>
                            value.toFixed(2),
                        )}
                        hint={`+${summary.gross_profit.toFixed(2)} won ÷ ${summary.gross_loss.toFixed(2)} lost`}
                    />
                    <StatCard
                        label="Expectancy"
                        value={orDash(
                            summary.expectancy,
                            (value) => `${signedUsdt(value)} USDT`,
                        )}
                        className={pnlClass(summary.expectancy)}
                        hint={orDash(
                            summary.average_roi_pct,
                            (value) =>
                                `Average per trade · ${formatPct(value, 2, true)} ROI`,
                        )}
                    />
                    <StatCard
                        label="Daily return on margin"
                        value={orDash(summary.daily_return_pct, (value) =>
                            formatPct(value, 2, true),
                        )}
                        className={pnlClass(summary.daily_return_pct)}
                        hint="PnL per margin per day running"
                    />
                    <StatCard
                        label="Average win / loss"
                        value={
                            <>
                                <span className={pnlClass(summary.average_win)}>
                                    {orDash(summary.average_win, signedUsdt)}
                                </span>
                                <span className="text-muted-foreground">
                                    {' / '}
                                </span>
                                <span
                                    className={pnlClass(summary.average_loss)}
                                >
                                    {orDash(summary.average_loss, signedUsdt)}
                                </span>
                            </>
                        }
                        hint={
                            payoffRatio === null
                                ? 'USDT per trade'
                                : `Payoff ratio ${payoffRatio.toFixed(2)}`
                        }
                    />
                    <StatCard
                        label="Max drawdown"
                        value={`${signedUsdt(summary.max_drawdown)} USDT`}
                        className={pnlClass(summary.max_drawdown)}
                        hint="Deepest fall from a cumulative PnL peak"
                    />
                    <StatCard
                        label="Best trade"
                        value={
                            summary.best_trade
                                ? signedUsdt(summary.best_trade.pnl)
                                : '–'
                        }
                        className={pnlClass(summary.best_trade?.pnl ?? null)}
                        hint={
                            summary.best_trade &&
                            `${summary.best_trade.symbol} · ${formatPct(summary.best_trade.roi_pct, 2, true)}`
                        }
                    />
                    <StatCard
                        label="Worst trade"
                        value={
                            summary.worst_trade
                                ? signedUsdt(summary.worst_trade.pnl)
                                : '–'
                        }
                        className={pnlClass(summary.worst_trade?.pnl ?? null)}
                        hint={
                            summary.worst_trade &&
                            `${summary.worst_trade.symbol} · ${formatPct(summary.worst_trade.roi_pct, 2, true)}`
                        }
                    />
                    <StatCard
                        label="Average duration"
                        value={orDash(
                            summary.average_duration_seconds,
                            formatDuration,
                        )}
                        hint={`${summary.total_matched_trades.toLocaleString('en-US')} matched trades · streaks ${summary.longest_win_streak}W / ${summary.longest_loss_streak}L`}
                    />
                </div>

                <Card className="gap-3 py-4">
                    <CardContent className="px-4">
                        <PnlCurve trades={trades} />
                    </CardContent>
                </Card>

                {pnlBreakdown && <PnlBreakdownCard breakdown={pnlBreakdown} />}

                {activeDimension && (
                    <Section
                        title="Results by setup"
                        note="Matched trades per day count every trade, including running ones"
                        actions={
                            <ToggleGroup
                                type="single"
                                variant="outline"
                                value={activeDimension.value}
                                onValueChange={(value) =>
                                    value && setDimension(value as Dimension)
                                }
                                className="flex-wrap"
                            >
                                {comparable.map((option) => (
                                    <ToggleGroupItem
                                        key={option.value}
                                        value={option.value}
                                        className="px-3"
                                    >
                                        {option.label}
                                    </ToggleGroupItem>
                                ))}
                            </ToggleGroup>
                        }
                    >
                        <ResultsTable
                            rows={breakdowns[activeDimension.value]}
                            column={activeDimension.column}
                        />
                    </Section>
                )}

                {bySymbol.length > 0 && (
                    <Section
                        title="Results by pair"
                        note={`${bySymbol.length} ${bySymbol.length === 1 ? 'pair' : 'pairs'}, most profitable first`}
                    >
                        <div className="max-h-[28rem] overflow-y-auto">
                            <ResultsTable rows={bySymbol} column="Pair" />
                        </div>
                    </Section>
                )}
            </div>
        </>
    );
}

Analytics.layout = {
    breadcrumbs: [
        {
            title: 'Analytics',
            href: analytics(),
        },
    ],
};
