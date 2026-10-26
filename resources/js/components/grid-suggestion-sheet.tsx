import { NotebookPen, TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import { GradeBadge, ModeBadge } from '@/components/grid-badges';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import {
    formatCompact,
    formatPct,
    formatPrice,
    formatUsdt,
} from '@/lib/grid-format';
import type { GridSuggestion } from '@/types';

const scoreLabels: Record<string, [string, number]> = {
    structure: ['Price structure', 30],
    volatility: ['Volatility', 20],
    profitability: ['Grid profitability', 20],
    liquidity: ['Liquidity', 15],
    funding: ['Funding', 10],
    risk_reward: ['Risk / reward', 5],
};

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex items-baseline justify-between gap-4 py-1.5 text-sm">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="text-right font-medium tabular-nums">{children}</dd>
        </div>
    );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="space-y-1">
            <h3 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                {title}
            </h3>
            <dl className="divide-y divide-border/60">{children}</dl>
        </section>
    );
}

export function entryTriggerLabel(
    suggestion: GridSuggestion,
    tickSize: number,
): string {
    if (
        suggestion.entry_trigger === 'immediate' ||
        suggestion.entry_trigger_price === null
    ) {
        return 'Start now';
    }

    const direction = suggestion.entry_trigger === 'below' ? '≤' : '≥';

    return `${direction} ${formatPrice(suggestion.entry_trigger_price, tickSize)}`;
}

export default function GridSuggestionSheet({
    suggestion,
    onOpenChange,
    onJournal,
}: {
    suggestion: GridSuggestion | null;
    onOpenChange: (open: boolean) => void;
    onJournal: (suggestion: GridSuggestion) => void;
}) {
    const tick = suggestion?.metrics.tick_size ?? 0;

    return (
        <Sheet open={suggestion !== null} onOpenChange={onOpenChange}>
            <SheetContent className="w-full overflow-y-auto sm:max-w-md">
                {suggestion && (
                    <>
                        <SheetHeader>
                            <SheetTitle className="flex items-center gap-2 text-lg">
                                {suggestion.symbol}
                                <ModeBadge mode={suggestion.mode} />
                                <GradeBadge grade={suggestion.grade} />
                            </SheetTitle>
                            <SheetDescription>
                                Score {suggestion.score}/100 · last price{' '}
                                {formatPrice(suggestion.last_price, tick)} ·{' '}
                                {formatPct(
                                    suggestion.price_change_24h_pct,
                                    2,
                                    true,
                                )}{' '}
                                24h
                            </SheetDescription>
                        </SheetHeader>

                        <div className="space-y-6 px-4 pb-6">
                            <Button
                                variant="outline"
                                className="w-full"
                                onClick={() => onJournal(suggestion)}
                            >
                                <NotebookPen className="size-4" />
                                Journal this trade
                            </Button>

                            {suggestion.below_min_notional && (
                                <Alert variant="destructive">
                                    <TriangleAlert />
                                    <AlertDescription>
                                        Each grid order would be{' '}
                                        {formatUsdt(
                                            suggestion.notional_per_grid,
                                        )}{' '}
                                        USDT, below Binance's minimum of{' '}
                                        {suggestion.metrics.min_notional} USDT.
                                        Raise the margin or use fewer grids.
                                    </AlertDescription>
                                </Alert>
                            )}

                            <Section title="Bot parameters">
                                <Row label="Lower limit">
                                    {formatPrice(suggestion.lower_price, tick)}
                                </Row>
                                <Row label="Upper limit">
                                    {formatPrice(suggestion.upper_price, tick)}
                                </Row>
                                <Row label="Range width">
                                    {formatPct(suggestion.range_width_pct)}
                                </Row>
                                <Row label="Number of grids">
                                    {suggestion.grid_count} (
                                    {suggestion.grid_type})
                                </Row>
                                <Row label="Grid spacing">
                                    {formatPct(suggestion.grid_spacing_pct)}
                                </Row>
                                <Row label="Profit per grid (after fees)">
                                    {formatPct(suggestion.profit_per_grid_pct)}
                                </Row>
                                <Row label="Leverage">
                                    {suggestion.leverage}x
                                </Row>
                                <Row label="Trailing up">
                                    {suggestion.trailing_up ? 'On' : 'Off'}
                                </Row>
                                <Row label="Trailing down">
                                    {suggestion.trailing_down ? 'On' : 'Off'}
                                </Row>
                                {suggestion.trailing_limit_price !== null && (
                                    <Row label="Trailing limit price">
                                        {formatPrice(
                                            suggestion.trailing_limit_price,
                                            tick,
                                        )}
                                    </Row>
                                )}
                                <Row label="Margin">
                                    {formatUsdt(suggestion.margin)} USDT (
                                    {formatPct(
                                        suggestion.margin_allocation_pct,
                                        1,
                                    )}{' '}
                                    of total)
                                </Row>
                                <Row label="Order size per grid">
                                    {formatUsdt(suggestion.notional_per_grid)}{' '}
                                    USDT
                                </Row>
                                <Row label="Entry trigger">
                                    {entryTriggerLabel(suggestion, tick)}
                                </Row>
                                <Row label="Take profit (PnL)">
                                    <span className="text-emerald-600 dark:text-emerald-400">
                                        {formatPct(
                                            suggestion.take_profit_pnl_pct,
                                            2,
                                            true,
                                        )}{' '}
                                        ·{' '}
                                        {formatUsdt(
                                            suggestion.take_profit_usdt,
                                        )}{' '}
                                        USDT
                                    </span>
                                </Row>
                                <Row label="Stop loss (PnL)">
                                    <span className="text-rose-600 dark:text-rose-400">
                                        {formatPct(
                                            suggestion.stop_loss_pnl_pct,
                                        )}{' '}
                                        ·{' '}
                                        {formatUsdt(suggestion.stop_loss_usdt)}{' '}
                                        USDT
                                    </span>
                                </Row>
                                <Row label="Est. daily ROI">
                                    {formatPct(
                                        suggestion.estimated_daily_roi_pct,
                                        3,
                                    )}
                                </Row>
                            </Section>

                            <Section title="Why this setup">
                                <ul className="list-disc space-y-1.5 py-1.5 pl-4 text-sm">
                                    {suggestion.reasons.map((reason) => (
                                        <li key={reason}>{reason}</li>
                                    ))}
                                </ul>
                            </Section>

                            <Section title="Score breakdown">
                                {Object.entries(scoreLabels).map(
                                    ([key, [label, max]]) => (
                                        <Row key={key} label={label}>
                                            {(
                                                suggestion.metrics
                                                    .score_breakdown[key] ?? 0
                                            ).toFixed(1)}{' '}
                                            / {max}
                                        </Row>
                                    ),
                                )}
                                {(suggestion.metrics.score_breakdown.penalty ??
                                    0) < 0 && (
                                    <Row label="Penalty (24h move > 20%)">
                                        {suggestion.metrics.score_breakdown.penalty.toFixed(
                                            1,
                                        )}
                                    </Row>
                                )}
                            </Section>

                            {suggestion.metrics.btc_beta != null && (
                                <Section title="BTC sensitivity">
                                    <Row label="Beta to BTC">
                                        {suggestion.metrics.btc_beta.toFixed(2)}
                                    </Row>
                                    <Row label="Correlation with BTC">
                                        {suggestion.metrics.btc_correlation?.toFixed(
                                            2,
                                        )}
                                    </Row>
                                    <Row label="Shock beta (BTC falls)">
                                        {suggestion.metrics.btc_shock_beta_down?.toFixed(
                                            2,
                                        )}{' '}
                                        <span className="text-xs text-muted-foreground">
                                            (
                                            {
                                                suggestion.metrics
                                                    .btc_shock_hours_down
                                            }{' '}
                                            h)
                                        </span>
                                    </Row>
                                    <Row label="Shock beta (BTC rallies)">
                                        {suggestion.metrics.btc_shock_beta_up?.toFixed(
                                            2,
                                        )}{' '}
                                        <span className="text-xs text-muted-foreground">
                                            (
                                            {
                                                suggestion.metrics
                                                    .btc_shock_hours_up
                                            }{' '}
                                            h)
                                        </span>
                                    </Row>
                                    {suggestion.metrics.btc_shock_move_pct !=
                                        null && (
                                        <Row label="BTC shock stress test">
                                            BTC{' '}
                                            {formatPct(
                                                (suggestion.metrics
                                                    .btc_shock_move_pct < 0
                                                    ? -1
                                                    : 1) *
                                                    (suggestion.metrics
                                                        .btc_shock_pct ?? 0),
                                                1,
                                                true,
                                            )}{' '}
                                            →{' '}
                                            {formatPct(
                                                suggestion.metrics
                                                    .btc_shock_move_pct,
                                                1,
                                                true,
                                            )}{' '}
                                            here ·{' '}
                                            <span className="text-rose-600 dark:text-rose-400">
                                                {formatPct(
                                                    suggestion.metrics
                                                        .btc_shock_loss_pct ??
                                                        0,
                                                    1,
                                                )}{' '}
                                                PnL
                                            </span>
                                        </Row>
                                    )}
                                </Section>
                            )}

                            <Section title="Market metrics">
                                <Row label="Daily volatility">
                                    {formatPct(
                                        suggestion.metrics.daily_volatility_pct,
                                    )}
                                </Row>
                                <Row label="ATR (1h)">
                                    {formatPct(suggestion.metrics.atr_pct, 3)}
                                </Row>
                                <Row label="ADX (4h)">
                                    {suggestion.metrics.adx_4h}
                                </Row>
                                <Row label="Efficiency ratio (7d)">
                                    {suggestion.metrics.efficiency_ratio}
                                </Row>
                                <Row label="Mean crossings / day">
                                    {suggestion.metrics.crossings_per_day}
                                </Row>
                                <Row label="RSI (1h)">
                                    {suggestion.metrics.rsi_1h}
                                </Row>
                                <Row label="Drift / day">
                                    {formatPct(
                                        suggestion.metrics.drift_per_day_pct,
                                        2,
                                        true,
                                    )}
                                </Row>
                                <Row label="Support / resistance (7d)">
                                    {formatPrice(
                                        suggestion.metrics.support,
                                        tick,
                                    )}{' '}
                                    /{' '}
                                    {formatPrice(
                                        suggestion.metrics.resistance,
                                        tick,
                                    )}
                                </Row>
                                <Row label="Breakout stop zone">
                                    {formatPrice(
                                        suggestion.metrics.breakout_down_price,
                                        tick,
                                    )}{' '}
                                    /{' '}
                                    {formatPrice(
                                        suggestion.metrics.breakout_up_price,
                                        tick,
                                    )}
                                </Row>
                                <Row label="Funding rate">
                                    {formatPct(
                                        suggestion.funding_rate_pct,
                                        4,
                                        true,
                                    )}
                                </Row>
                                <Row label="24h volume">
                                    {formatCompact(suggestion.quote_volume_24h)}{' '}
                                    USDT
                                </Row>
                            </Section>
                        </div>
                    </>
                )}
            </SheetContent>
        </Sheet>
    );
}
