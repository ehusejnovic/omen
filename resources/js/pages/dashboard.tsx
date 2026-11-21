import { Form, Head, Link, usePoll } from "@inertiajs/react";
import { RefreshCw, Search, TriangleAlert } from "lucide-react";
import { useEffect, useMemo, useState } from "react";
import type { ReactNode } from "react";
import GridScannerController from "@/actions/App/Http/Controllers/GridScannerController";
import { GradeBadge, ModeBadge } from "@/components/grid-badges";
import GridTradeDialog from "@/components/grid-trade-dialog";
import GridSuggestionSheet, {
    dailyTrendLabel,
    entryTriggerLabel,
    runtimeLabel,
} from "@/components/grid-suggestion-sheet";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";
import { ToggleGroup, ToggleGroupItem } from "@/components/ui/toggle-group";
import {
    formatPct,
    formatPrice,
    formatRelative,
    formatUsdt,
} from "@/lib/grid-format";
import { cn } from "@/lib/utils";
import { dashboard } from "@/routes";
import { edit as editGridSettings } from "@/routes/grid-settings";
import type {
    GridGrade,
    GridMode,
    GridScan,
    GridStrategy,
    GridSuggestion,
} from "@/types";

const grades: GridGrade[] = ["A+", "A", "B+", "B", "C+", "C"];

type ModeFilter = GridMode | "all";

type MarketFilter = "all" | "crypto" | "tradfi";

const scanIntervalMinutes: Record<GridStrategy, number> = {
    swing: 60,
    volatile: 5,
};

const strategyTabs: { value: GridStrategy; label: string }[] = [
    { value: "swing", label: "Swing (3–7 days)" },
    { value: "volatile", label: "Volatile (≤ 2h)" },
];

function nextScanLabel(strategy: GridStrategy): string {
    const interval = scanIntervalMinutes[strategy];
    const minutes = interval - (new Date().getMinutes() % interval);

    return minutes === interval ? "now" : `in ${minutes} min`;
}

function StrategyTabs({ strategy }: { strategy: GridStrategy }) {
    return (
        <div className="inline-flex gap-1 rounded-lg bg-neutral-100 p-1 dark:bg-neutral-800">
            {strategyTabs.map(({ value, label }) => (
                <Link
                    key={value}
                    href={dashboard(
                        value === "swing"
                            ? undefined
                            : { query: { strategy: value } },
                    )}
                    preserveScroll
                    className={cn(
                        "rounded-md px-3.5 py-1.5 text-sm transition-colors",
                        strategy === value
                            ? "bg-white shadow-xs dark:bg-neutral-700 dark:text-neutral-100"
                            : "text-neutral-500 hover:bg-neutral-200/60 hover:text-black dark:text-neutral-400 dark:hover:bg-neutral-700/60",
                    )}
                >
                    {label}
                </Link>
            ))}
        </div>
    );
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

type ScannerProps = {
    strategy: GridStrategy;
    scan: GridScan | null;
    isScanning: boolean;
    totalMargin: number;
    rangeHorizonOptions: number[];
    runtimeMinutes: number;
    symbols: string[];
    suggestions: GridSuggestion[];
};

export default function Dashboard(props: ScannerProps) {
    // Keyed by strategy so filters, the open sheet and the poll interval start fresh on each tab.
    return <Scanner key={props.strategy} {...props} />;
}

function Scanner({
    strategy,
    scan,
    isScanning,
    totalMargin,
    rangeHorizonOptions,
    runtimeMinutes,
    symbols,
    suggestions,
}: ScannerProps) {
    const volatile = strategy === "volatile";
    const runtime = runtimeLabel(runtimeMinutes);
    const [mode, setMode] = useState<ModeFilter>("all");
    const [market, setMarket] = useState<MarketFilter>("all");
    const [minimumGrade, setMinimumGrade] = useState<GridGrade>("C");
    const [query, setQuery] = useState("");
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [journaling, setJournaling] = useState<GridSuggestion | null>(null);

    const { start: startScanPoll, stop: stopScanPoll } = usePoll(
        10000,
        { only: ["scan", "isScanning", "suggestions"] },
        { autoStart: false },
    );
    usePoll(volatile ? 60000 : 300000, {
        only: ["scan", "isScanning", "suggestions"],
    });

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
                (mode === "all" || suggestion.mode === mode) &&
                (market === "all" ||
                    suggestion.is_tradfi === (market === "tradfi")) &&
                grades.indexOf(suggestion.grade) <= gradeLimit &&
                (search === "" || suggestion.symbol.includes(search)),
        );
    }, [suggestions, mode, market, minimumGrade, query]);

    const selected =
        suggestions.find((suggestion) => suggestion.id === selectedId) ?? null;
    const topCount = suggestions.filter((suggestion) =>
        ["A+", "A"].includes(suggestion.grade),
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
                    <div className="space-y-3">
                        <div>
                            <h1 className="text-xl font-semibold tracking-tight">
                                Futures grid scanner
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                {volatile
                                    ? `Short bots of up to ${runtime} on highly volatile Binance USDⓈ-M perpetuals that have stopped trending and settled into a range, rescanned every 5 minutes. ${scan?.symbols_scanned ?? 0} volatile pairs checked in the last scan.`
                                    : `Best ${scan?.suggestions_count ?? 30} setups by grade from the top ${scan?.symbols_scanned ?? 100} Binance USDⓈ-M perpetuals by volume, rescanned every hour.`}{" "}
                                Suggestions only, no orders are placed.
                            </p>
                        </div>
                        <StrategyTabs strategy={strategy} />
                    </div>
                    <Form {...GridScannerController.store.form()}>
                        {({ processing }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="strategy"
                                    value={strategy}
                                />
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
                                    {isScanning ? "Scanning…" : "Scan now"}
                                </Button>
                            </>
                        )}
                    </Form>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard
                        label="Last scan"
                        value={
                            scan?.finished_at
                                ? formatRelative(scan.finished_at)
                                : "Never"
                        }
                        hint={
                            volatile
                                ? `Up to ${runtime} bots · next scan ${nextScanLabel(strategy)}`
                                : `${scan?.spacing_multiplier ? `Spacing ${scan.spacing_multiplier}× ATR · ` : ""}${scan?.range_horizon_days ? `${scan.range_horizon_days}-day range · ` : ""}next scan ${nextScanLabel(strategy)}`
                        }
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

                    <ToggleGroup
                        type="single"
                        variant="outline"
                        value={market}
                        onValueChange={(value) =>
                            value && setMarket(value as MarketFilter)
                        }
                    >
                        <ToggleGroupItem value="all" className="px-3">
                            All markets
                        </ToggleGroupItem>
                        <ToggleGroupItem value="crypto" className="px-3">
                            Crypto
                        </ToggleGroupItem>
                        <ToggleGroupItem value="tradfi" className="px-3">
                            TradFi
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
                                    {grade === "C"
                                        ? "All grades"
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
                                <th>
                                    {volatile ? "Start price" : "Entry trigger"}
                                </th>
                                <th className="text-right">TP / SL (PnL)</th>
                                <th className="text-right">
                                    {volatile
                                        ? `Est. ${runtime} ROI`
                                        : "Est. daily ROI"}
                                </th>
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
                                                )}{" "}
                                                <span
                                                    className={cn(
                                                        suggestion.price_change_24h_pct >=
                                                            0
                                                            ? "text-emerald-600 dark:text-emerald-400"
                                                            : "text-rose-600 dark:text-rose-400",
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
                                            {dailyTrendLabel(suggestion) && (
                                                <div className="mt-1 text-xs text-muted-foreground">
                                                    Daily:{" "}
                                                    {dailyTrendLabel(
                                                        suggestion,
                                                    )?.toLowerCase()}
                                                </div>
                                            )}
                                            {(suggestion.trailing_up ||
                                                suggestion.trailing_down) && (
                                                <div className="text-xs text-muted-foreground">
                                                    {suggestion.trailing_up
                                                        ? "Trailing up"
                                                        : "Trailing down"}
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
                                            )}{" "}
                                            –{" "}
                                            {formatPrice(
                                                suggestion.upper_price,
                                                tick,
                                            )}
                                            <div className="text-xs text-muted-foreground">
                                                {formatPct(
                                                    suggestion.range_width_pct,
                                                    1,
                                                )}{" "}
                                                wide
                                            </div>
                                        </td>
                                        <td className="text-right tabular-nums">
                                            {suggestion.grid_count}
                                            <div className="text-xs text-muted-foreground">
                                                {formatPct(
                                                    suggestion.profit_per_grid_pct,
                                                )}{" "}
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
                                            {volatile &&
                                                suggestion.metrics
                                                    .fair_value !==
                                                    undefined && (
                                                    <div className="text-xs text-muted-foreground">
                                                        Fair value{" "}
                                                        {formatPrice(
                                                            suggestion.metrics
                                                                .fair_value,
                                                            tick,
                                                        )}
                                                    </div>
                                                )}
                                        </td>
                                        <td className="text-right tabular-nums">
                                            <span className="text-emerald-600 dark:text-emerald-400">
                                                {formatPct(
                                                    suggestion.take_profit_pnl_pct,
                                                    1,
                                                    true,
                                                )}
                                            </span>{" "}
                                            /{" "}
                                            <span className="text-rose-600 dark:text-rose-400">
                                                {formatPct(
                                                    suggestion.stop_loss_pnl_pct,
                                                    1,
                                                )}
                                            </span>
                                            <div className="text-xs text-muted-foreground">
                                                {formatUsdt(
                                                    suggestion.take_profit_usdt,
                                                )}{" "}
                                                /{" "}
                                                {formatUsdt(
                                                    suggestion.stop_loss_usdt,
                                                )}{" "}
                                                USDT
                                            </div>
                                        </td>
                                        <td className="text-right tabular-nums">
                                            {formatPct(
                                                volatile
                                                    ? (suggestion.metrics
                                                          .runtime_roi_pct ?? 0)
                                                    : suggestion.estimated_daily_roi_pct,
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
                                            ? "No scan has finished yet. Run a scan to see suggestions."
                                            : suggestions.length === 0 &&
                                                volatile
                                              ? "No volatile pair is stabilizing right now. The scanner checks again every 5 minutes."
                                              : "No suggestions match these filters."}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            <GridSuggestionSheet
                suggestion={selected}
                strategy={strategy}
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
                horizonOptions={rangeHorizonOptions}
                symbols={symbols}
            />
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: "Grid scanner",
            href: dashboard(),
        },
    ],
};
