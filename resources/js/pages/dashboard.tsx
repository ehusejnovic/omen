import { Form, Head, Link, usePoll } from '@inertiajs/react';
import { RefreshCw, Search, TriangleAlert } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import GridScannerController from '@/actions/App/Http/Controllers/GridScannerController';
import { GradeBadge, ModeBadge } from '@/components/grid-badges';
import GridTradeDialog from '@/components/grid-trade-dialog';
import GridSuggestionSheet, {
    entryTriggerLabel,
} from '@/components/grid-suggestion-sheet';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    formatPct,
    formatPrice,
    formatRelative,
    formatUsdt,
} from '@/lib/grid-format';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { edit as editGridSettings } from '@/routes/grid-settings';
import type { GridGrade, GridMode, GridScan, GridSuggestion } from '@/types';

const grades: GridGrade[] = ['A+', 'A', 'B+', 'B', 'C+', 'C'];

type ModeFilter = GridMode | 'all';

function nextScanLabel(): string {
    const minutes = 60 - new Date().getMinutes();

    return minutes === 60 ? 'now' : `in ${minutes} min`;
}

function StatCard({
    label,
    value,
    hint,
}: {
    label: string;
    value: ReactNode;
    hint?: ReactNode;
}) {
    return (
        <Card className="gap-1 py-4">
            <CardContent className="space-y-1 px-4">
                <p className="text-xs text-muted-foreground">{label}</p>
                <p className="text-xl font-semibold tabular-nums">{value}</p>
                {hint && (
                    <p className="text-xs text-muted-foreground">{hint}</p>
                )}
            </CardContent>
        </Card>
    );
}

export default function Dashboard({
    scan,
    isScanning,
    totalMargin,
    suggestions,
}: {
    scan: GridScan | null;
    isScanning: boolean;
    totalMargin: number;
    suggestions: GridSuggestion[];
}) {
    const [mode, setMode] = useState<ModeFilter>('all');
    const [minimumGrade, setMinimumGrade] = useState<GridGrade>('C');
    const [query, setQuery] = useState('');
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [journaling, setJournaling] = useState<GridSuggestion | null>(null);

    const { start: startScanPoll, stop: stopScanPoll } = usePoll(
        10000,
        { only: ['scan', 'isScanning', 'suggestions'] },
        { autoStart: false },
    );
    usePoll(300000, { only: ['scan', 'isScanning', 'suggestions'] });

    useEffect(() => {
        if (isScanning) {
            startScanPoll();
        } else {
            stopScanPoll();
        }
    }, [isScanning, startScanPoll, stopScanPoll]);

    const visible = useMemo(() => {
        const gradeLimit = grades.indexOf(minimumGrade);
        const search = query.trim().toUpperCase();

        return suggestions.filter(
            (suggestion) =>
                (mode === 'all' || suggestion.mode === mode) &&
                grades.indexOf(suggestion.grade) <= gradeLimit &&
                (search === '' || suggestion.symbol.includes(search)),
        );
    }, [suggestions, mode, minimumGrade, query]);

    const selected =
        suggestions.find((suggestion) => suggestion.id === selectedId) ?? null;
    const topCount = suggestions.filter((suggestion) =>
        ['A+', 'A'].includes(suggestion.grade),
    ).length;
    const modeCounts = suggestions.reduce<Record<GridMode, number>>(
        (counts, suggestion) => ({
            ...counts,
            [suggestion.mode]: counts[suggestion.mode] + 1,
        }),
        { normal: 0, long: 0, short: 0 },
    );

    return (
        <>
            <Head title="Grid scanner" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-semibold tracking-tight">
                            Futures grid scanner
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Best {scan?.suggestions_count ?? 30} setups by grade
                            from the top {scan?.symbols_scanned ?? 100} Binance
                            USDⓈ-M perpetuals by volume, rescanned every hour.
                            Suggestions only, no orders are placed.
                        </p>
                    </div>
                    <Form {...GridScannerController.store.form()}>
                        {({ processing }) => (
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={processing || isScanning}
                            >
                                {isScanning ? (
                                    <Spinner />
                                ) : (
                                    <RefreshCw className="size-4" />
                                )}
                                {isScanning ? 'Scanning…' : 'Scan now'}
                            </Button>
                        )}
                    </Form>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard
                        label="Last scan"
                        value={
                            scan?.finished_at
                                ? formatRelative(scan.finished_at)
                                : 'Never'
                        }
                        hint={`${scan?.spacing_multiplier ? `Spacing ${scan.spacing_multiplier}× ATR · ` : ''}next scan ${nextScanLabel()}`}
                    />
                    <StatCard
                        label="Total margin"
                        value={`${formatUsdt(totalMargin)} USDT`}
                        hint={
                            <Link
                                href={editGridSettings()}
                                className="underline underline-offset-4"
                            >
                                Change in settings
                            </Link>
                        }
                    />
                    <StatCard
                        label="A+ / A setups"
                        value={topCount}
                        hint={`in the top ${suggestions.length} of ${scan?.symbols_scanned ?? 0} scanned`}
                    />
                    <StatCard
                        label="Normal / Long / Short"
                        value={`${modeCounts.normal} / ${modeCounts.long} / ${modeCounts.short}`}
                        hint="Bot direction mix"
                    />
                </div>

                <div className="flex flex-wrap items-center gap-3">
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        value={mode}
                        onValueChange={(value) =>
                            value && setMode(value as ModeFilter)
                        }
                    >
                        <ToggleGroupItem value="all" className="px-3">
                            All
                        </ToggleGroupItem>
                        <ToggleGroupItem value="normal" className="px-3">
                            Normal
                        </ToggleGroupItem>
                        <ToggleGroupItem value="long" className="px-3">
                            Long
                        </ToggleGroupItem>
                        <ToggleGroupItem value="short" className="px-3">
                            Short
                        </ToggleGroupItem>
                    </ToggleGroup>

                    <Select
                        value={minimumGrade}
                        onValueChange={(value) =>
                            setMinimumGrade(value as GridGrade)
                        }
                    >
                        <SelectTrigger className="w-40">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {grades.map((grade) => (
                                <SelectItem key={grade} value={grade}>
                                    {grade === 'C'
                                        ? 'All grades'
                                        : `${grade} and better`}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <div className="relative w-full sm:w-56">
                        <Search className="absolute top-2.5 left-2.5 size-4 text-muted-foreground" />
                        <Input
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder="Search symbol"
                            className="pl-8"
                        />
                    </div>

                    <span className="ml-auto text-sm text-muted-foreground">
                        {visible.length} shown
                    </span>
                </div>

                <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                            <tr className="[&>th]:px-3 [&>th]:py-2.5 [&>th]:font-medium [&>th]:whitespace-nowrap">
                                <th>Symbol</th>
                                <th>Bot</th>
                                <th>Grade</th>
                                <th>Range (lower – upper)</th>
                                <th className="text-right">Grids</th>
                                <th className="text-right">Leverage</th>
                                <th className="text-right">Margin</th>
                                <th>Entry trigger</th>
                                <th className="text-right">TP / SL (PnL)</th>
                                <th className="text-right">Est. daily ROI</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-border/60">
                            {visible.map((suggestion) => {
                                const tick = suggestion.metrics.tick_size;

                                return (
                                    <tr
                                        key={suggestion.id}
                                        onClick={() =>
                                            setSelectedId(suggestion.id)
                                        }
                                        className="cursor-pointer transition-colors hover:bg-muted/40 [&>td]:px-3 [&>td]:py-2.5 [&>td]:whitespace-nowrap"
                                    >
                                        <td>
                                            <div className="font-medium">
                                                {suggestion.symbol}
                                            </div>
                                            <div className="text-xs text-muted-foreground tabular-nums">
                                                {formatPrice(
                                                    suggestion.last_price,
                                                    tick,
                                                )}{' '}
                                                <span
                                                    className={cn(
                                                        suggestion.price_change_24h_pct >=
                                                            0
                                                            ? 'text-emerald-600 dark:text-emerald-400'
                                                            : 'text-rose-600 dark:text-rose-400',
                                                    )}
                                                >
                                                    {formatPct(
                                                        suggestion.price_change_24h_pct,
                                                        2,
                                                        true,
                                                    )}
                                                </span>
                                            </div>
                                        </td>
                                        <td>
                                            <ModeBadge mode={suggestion.mode} />
                                            {(suggestion.trailing_up ||
                                                suggestion.trailing_down) && (
                                                <div className="mt-1 text-xs text-muted-foreground">
                                                    {suggestion.trailing_up
                                                        ? 'Trailing up'
                                                        : 'Trailing down'}
                                                </div>
                                            )}
                                        </td>
                                        <td>
                                            <div className="flex items-center gap-2">
                                                <GradeBadge
                                                    grade={suggestion.grade}
                                                />
                                                <span className="text-xs text-muted-foreground tabular-nums">
                                                    {suggestion.score}
                                                </span>
                                            </div>
                                        </td>
                                        <td className="tabular-nums">
                                            {formatPrice(
                                                suggestion.lower_price,
                                                tick,
                                            )}{' '}
                                            –{' '}
                                            {formatPrice(
                                                suggestion.upper_price,
                                                tick,
                                            )}
                                            <div className="text-xs text-muted-foreground">
                                                {formatPct(
                                                    suggestion.range_width_pct,
                                                    1,
                                                )}{' '}
                                                wide
                                            </div>
                                        </td>
                                        <td className="text-right tabular-nums">
                                            {suggestion.grid_count}
                                            <div className="text-xs text-muted-foreground">
                                                {formatPct(
                                                    suggestion.profit_per_grid_pct,
                                                )}{' '}
                                                / grid
                                            </div>
                                        </td>
                                        <td className="text-right font-medium tabular-nums">
                                            {suggestion.leverage}x
                                        </td>
                                        <td className="text-right tabular-nums">
                                            <span className="inline-flex items-center gap-1">
                                                {suggestion.below_min_notional && (
                                                    <TriangleAlert
                                                        className="size-3.5 text-amber-500"
                                                        aria-label="Order size below Binance minimum"
                                                    />
                                                )}
                                                {formatUsdt(suggestion.margin)}
                                            </span>
                                            <div className="text-xs text-muted-foreground">
                                                {formatPct(
                                                    suggestion.margin_allocation_pct,
                                                    1,
                                                )}
                                            </div>
                                        </td>
                                        <td className="tabular-nums">
                                            {entryTriggerLabel(
                                                suggestion,
                                                tick,
                                            )}
                                        </td>
                                        <td className="text-right tabular-nums">
                                            <span className="text-emerald-600 dark:text-emerald-400">
                                                {formatPct(
                                                    suggestion.take_profit_pnl_pct,
                                                    1,
                                                    true,
                                                )}
                                            </span>{' '}
                                            /{' '}
                                            <span className="text-rose-600 dark:text-rose-400">
                                                {formatPct(
                                                    suggestion.stop_loss_pnl_pct,
                                                    1,
                                                )}
                                            </span>
                                            <div className="text-xs text-muted-foreground">
                                                {formatUsdt(
                                                    suggestion.take_profit_usdt,
                                                )}{' '}
                                                /{' '}
                                                {formatUsdt(
                                                    suggestion.stop_loss_usdt,
                                                )}{' '}
                                                USDT
                                            </div>
                                        </td>
                                        <td className="text-right tabular-nums">
                                            {formatPct(
                                                suggestion.estimated_daily_roi_pct,
                                                2,
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}
                            {visible.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={10}
                                        className="px-3 py-12 text-center text-muted-foreground"
                                    >
                                        {scan === null
                                            ? 'No scan has finished yet. Run a scan to see suggestions.'
                                            : 'No suggestions match these filters.'}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            <GridSuggestionSheet
                suggestion={selected}
                onOpenChange={(open) => !open && setSelectedId(null)}
                onJournal={(suggestion) => {
                    setSelectedId(null);
                    setJournaling(suggestion);
                }}
            />

            <GridTradeDialog
                open={journaling !== null}
                onOpenChange={(open) => !open && setJournaling(null)}
                suggestion={journaling}
            />
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Grid scanner',
            href: dashboard(),
        },
    ],
};
