import { useEffect, useId, useMemo, useRef, useState } from "react";
import type { KeyboardEvent, PointerEvent } from "react";
import { Button } from "@/components/ui/button";
import { formatUsdt } from "@/lib/grid-format";
import { cn } from "@/lib/utils";
import type { GridTrade } from "@/types";

type Day = {
    key: string;
    date: Date;
    pnl: number;
    trades: number;
    cumulative: number;
};

const LINE_HEIGHT = 180;
const BAR_HEIGHT = 96;
const GAP = 44;
const AXIS_HEIGHT = 22;
const PADDING_LEFT = 56;
const PADDING_RIGHT = 64;

const PROFIT = "#4cb782";
const LOSS = "#e5535f";
const MAX_DOTS = 40;

const profitFill = "fill-[#4cb782]";
const lossFill = "fill-[#e5535f]";
const profitBg = "bg-[#4cb782]";
const lossBg = "bg-[#e5535f]";
const gridStroke = "stroke-[#e1e0d9] dark:stroke-[#34343a]";
const baselineStroke = "stroke-[#c3c2b7] dark:stroke-[#45454c]";
const axisText = "fill-[#898781] text-[11px] tabular-nums";

/**
 * Smooth monotone cubic path through evenly spaced points, so the curve never overshoots a value.
 */
function smoothPath(points: { x: number; y: number }[]): string {
    if (points.length < 2) {
        return "";
    }

    const step = points[1].x - points[0].x;
    const slopes = points
        .slice(1)
        .map((point, index) => (point.y - points[index].y) / step);
    const tangents = points.map((_, index) => {
        if (index === 0) {
            return slopes[0];
        }

        if (index === points.length - 1) {
            return slopes[index - 1];
        }

        const before = slopes[index - 1];
        const after = slopes[index];

        if (before * after <= 0) {
            return 0;
        }

        return (
            Math.sign(before) *
            Math.min(
                Math.abs(before),
                Math.abs(after),
                Math.abs((before + after) / 2),
            )
        );
    });

    return points
        .map((point, index) => {
            if (index === 0) {
                return `M${point.x},${point.y}`;
            }

            const previous = points[index - 1];

            return `C${previous.x + step / 3},${previous.y + (tangents[index - 1] * step) / 3} ${point.x - step / 3},${point.y - (tangents[index] * step) / 3} ${point.x},${point.y}`;
        })
        .join("");
}

function localDateKey(date: Date): string {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}

/**
 * Daily PnL of finished trades, by the local day each trade ended, with empty days filled in.
 */
function buildDays(trades: GridTrade[]): Day[] {
    const totals = new Map<string, { pnl: number; trades: number }>();

    for (const trade of trades) {
        if (trade.ended_at === null || trade.pnl === null) {
            continue;
        }

        const key = localDateKey(new Date(trade.ended_at));
        const day = totals.get(key) ?? { pnl: 0, trades: 0 };
        totals.set(key, { pnl: day.pnl + trade.pnl, trades: day.trades + 1 });
    }

    if (totals.size === 0) {
        return [];
    }

    const keys = [...totals.keys()].sort();
    const [firstYear, firstMonth, firstDay] = keys[0].split("-").map(Number);
    const last = keys[keys.length - 1];
    const days: Day[] = [];
    let cumulative = 0;

    for (
        let date = new Date(firstYear, firstMonth - 1, firstDay);
        localDateKey(date) <= last;
        date = new Date(date.getFullYear(), date.getMonth(), date.getDate() + 1)
    ) {
        const key = localDateKey(date);
        const total = totals.get(key) ?? { pnl: 0, trades: 0 };
        cumulative += total.pnl;
        days.push({
            key,
            date,
            pnl: total.pnl,
            trades: total.trades,
            cumulative,
        });
    }

    return days;
}

/**
 * Round tick values covering [min, max], always including zero.
 */
function niceTicks(min: number, max: number, count = 4): number[] {
    const low = Math.min(0, min);
    const high = Math.max(0, max);
    const span = high - low || 1;
    const rough = span / count;
    const magnitude = 10 ** Math.floor(Math.log10(rough));
    const step =
        [1, 2, 2.5, 5, 10].map((m) => m * magnitude).find((s) => s >= rough) ??
        rough;
    const ticks: number[] = [];

    for (
        let tick = Math.floor(low / step) * step;
        tick <= high + step * 0.001;
        tick += step
    ) {
        ticks.push(Math.round(tick * 1e6) / 1e6);
    }

    if (ticks[ticks.length - 1] < high) {
        ticks.push(ticks[ticks.length - 1] + step);
    }

    return ticks;
}

function formatTick(value: number, compact: boolean): string {
    return compact
        ? Intl.NumberFormat("en-US", {
              notation: "compact",
              maximumFractionDigits: 1,
          }).format(value)
        : value.toLocaleString("en-US", { maximumFractionDigits: 2 });
}

function signed(value: number): string {
    return `${value > 0 ? "+" : ""}${formatUsdt(value)}`;
}

function formatDay(date: Date, withWeekday = false): string {
    return date.toLocaleDateString("en-GB", {
        weekday: withWeekday ? "short" : undefined,
        day: "numeric",
        month: "short",
    });
}

/**
 * Bar with a rounded data end and a square end on the baseline.
 */
function barPath(x: number, width: number, from: number, to: number): string {
    const height = Math.abs(to - from);
    const radius = Math.min(4, height, width / 2);

    if (to <= from) {
        return `M${x},${from}V${to + radius}Q${x},${to} ${x + radius},${to}H${x + width - radius}Q${x + width},${to} ${x + width},${to + radius}V${from}Z`;
    }

    return `M${x},${from}V${to - radius}Q${x},${to} ${x + radius},${to}H${x + width - radius}Q${x + width},${to} ${x + width},${to - radius}V${from}Z`;
}

export default function PnlCurve({ trades }: { trades: GridTrade[] }) {
    const days = useMemo(() => buildDays(trades), [trades]);
    const containerRef = useRef<HTMLDivElement>(null);
    const [width, setWidth] = useState(0);
    const [active, setActive] = useState<number | null>(null);
    const [showTable, setShowTable] = useState(false);
    const svgId = useId().replace(/[^a-zA-Z0-9]/g, "");

    useEffect(() => {
        const element = containerRef.current;

        if (!element) {
            return;
        }

        const observer = new ResizeObserver(([entry]) =>
            setWidth(entry.contentRect.width),
        );
        observer.observe(element);

        return () => observer.disconnect();
    }, []);

    const finishedTrades = days.reduce((sum, day) => sum + day.trades, 0);
    const total = days.at(-1)?.cumulative ?? 0;

    if (days.length === 0) {
        return (
            <p className="py-8 text-center text-sm text-muted-foreground">
                The curve appears once a trade is finished with a recorded PnL.
            </p>
        );
    }

    const innerWidth = Math.max(width - PADDING_LEFT - PADDING_RIGHT, 1);
    const slot = innerWidth / days.length;
    const xAt = (index: number) => PADDING_LEFT + slot * (index + 0.5);
    const barWidth = Math.max(Math.min(24, slot - 2), 1);

    const lineTicks = niceTicks(
        Math.min(...days.map((day) => day.cumulative)),
        Math.max(...days.map((day) => day.cumulative)),
    );
    const lineTop = 8;
    const lineBottom = LINE_HEIGHT;
    const lineY = (value: number) =>
        lineBottom -
        ((value - lineTicks[0]) / (lineTicks.at(-1)! - lineTicks[0] || 1)) *
            (lineBottom - lineTop);

    const barTicks = niceTicks(
        Math.min(...days.map((day) => day.pnl)),
        Math.max(...days.map((day) => day.pnl)),
        2,
    );
    const barTop = LINE_HEIGHT + GAP;
    const barBottom = barTop + BAR_HEIGHT;
    const barY = (value: number) =>
        barBottom -
        ((value - barTicks[0]) / (barTicks.at(-1)! - barTicks[0] || 1)) *
            BAR_HEIGHT;

    const height = barBottom + AXIS_HEIGHT;
    const compactLine = Math.max(...lineTicks.map(Math.abs)) >= 10000;
    const compactBars = Math.max(...barTicks.map(Math.abs)) >= 10000;
    const zeroY = lineY(0);
    const linePath = smoothPath(
        days.map((day, index) => ({ x: xAt(index), y: lineY(day.cumulative) })),
    );
    const areaPath = `${linePath}L${xAt(days.length - 1)},${zeroY}L${xAt(0)},${zeroY}Z`;
    const showDots = days.length <= MAX_DOTS;
    const signFill = (value: number) => (value < 0 ? lossFill : profitFill);
    const labelEvery = Math.max(
        1,
        Math.ceil(days.length / Math.max(1, Math.floor(innerWidth / 64))),
    );
    const activeDay = active === null ? null : days[active];

    function indexFromPointer(event: PointerEvent<SVGRectElement>): number {
        const bounds = event.currentTarget.getBoundingClientRect();
        const index = Math.floor((event.clientX - bounds.left) / slot);

        return Math.min(days.length - 1, Math.max(0, index));
    }

    function handleKeyDown(event: KeyboardEvent<HTMLDivElement>) {
        const moves: Record<string, number> = {
            ArrowLeft: -1,
            ArrowRight: 1,
            Home: -Infinity,
            End: Infinity,
        };

        if (!(event.key in moves)) {
            return;
        }

        event.preventDefault();
        setActive((current) =>
            Math.min(
                days.length - 1,
                Math.max(0, (current ?? days.length - 1) + moves[event.key]),
            ),
        );
    }

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <div>
                    <h2 className="text-lg font-semibold">PnL Curve</h2>
                    <p className="text-xs text-muted-foreground">
                        Finished trades only, by the day they ended (your local
                        time) · {finishedTrades}{" "}
                        {finishedTrades === 1 ? "trade" : "trades"} over{" "}
                        {days.length} {days.length === 1 ? "day" : "days"}
                    </p>
                </div>
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => setShowTable((shown) => !shown)}
                    aria-expanded={showTable}
                >
                    {showTable ? "Hide table" : "Show table"}
                </Button>
            </div>

            <div
                ref={containerRef}
                className="relative rounded-md outline-none focus-visible:ring-2 focus-visible:ring-ring"
                tabIndex={0}
                role="group"
                aria-label={`PnL curve: cumulative PnL ${signed(total)} USDT from ${finishedTrades} finished trades. Use the left and right arrow keys to read each day.`}
                onKeyDown={handleKeyDown}
                onFocus={() =>
                    setActive((current) => current ?? days.length - 1)
                }
                onBlur={() => setActive(null)}
            >
                {width > 0 && (
                    <svg
                        width={width}
                        height={height}
                        className="block overflow-visible"
                        aria-hidden
                    >
                        <text
                            x={PADDING_LEFT}
                            y={lineTop - 2}
                            className={axisText}
                            dominantBaseline="auto"
                        >
                            Cumulative PnL (USDT)
                        </text>
                        {lineTicks.map((tick) => (
                            <g key={`line-${tick}`}>
                                <line
                                    x1={PADDING_LEFT}
                                    x2={width - PADDING_RIGHT}
                                    y1={lineY(tick)}
                                    y2={lineY(tick)}
                                    className={
                                        tick === 0 ? baselineStroke : gridStroke
                                    }
                                    strokeWidth={1}
                                />
                                <text
                                    x={PADDING_LEFT - 8}
                                    y={lineY(tick)}
                                    textAnchor="end"
                                    dominantBaseline="middle"
                                    className={axisText}
                                >
                                    {formatTick(tick, compactLine)}
                                </text>
                            </g>
                        ))}
                        <defs>
                            <clipPath id={`${svgId}-above`}>
                                <rect
                                    x={0}
                                    y={0}
                                    width={width}
                                    height={zeroY}
                                />
                            </clipPath>
                            <clipPath id={`${svgId}-below`}>
                                <rect
                                    x={0}
                                    y={zeroY}
                                    width={width}
                                    height={Math.max(lineBottom + 8 - zeroY, 0)}
                                />
                            </clipPath>
                            <linearGradient
                                id={`${svgId}-profit`}
                                gradientUnits="userSpaceOnUse"
                                x1={0}
                                x2={0}
                                y1={lineTop}
                                y2={zeroY}
                            >
                                <stop
                                    offset="0%"
                                    stopColor={PROFIT}
                                    stopOpacity={0.22}
                                />
                                <stop
                                    offset="100%"
                                    stopColor={PROFIT}
                                    stopOpacity={0.02}
                                />
                            </linearGradient>
                            <linearGradient
                                id={`${svgId}-loss`}
                                gradientUnits="userSpaceOnUse"
                                x1={0}
                                x2={0}
                                y1={lineBottom}
                                y2={zeroY}
                            >
                                <stop
                                    offset="0%"
                                    stopColor={LOSS}
                                    stopOpacity={0.22}
                                />
                                <stop
                                    offset="100%"
                                    stopColor={LOSS}
                                    stopOpacity={0.02}
                                />
                            </linearGradient>
                        </defs>
                        {days.length > 1 &&
                            (
                                [
                                    ["above", "profit", PROFIT],
                                    ["below", "loss", LOSS],
                                ] as const
                            ).map(([clip, gradient, color]) => (
                                <g
                                    key={clip}
                                    clipPath={`url(#${svgId}-${clip})`}
                                >
                                    <path
                                        d={areaPath}
                                        fill={`url(#${svgId}-${gradient})`}
                                    />
                                    <path
                                        d={linePath}
                                        fill="none"
                                        stroke={color}
                                        strokeWidth={2.5}
                                        strokeLinejoin="round"
                                        strokeLinecap="round"
                                    />
                                </g>
                            ))}
                        {showDots &&
                            days.map((day, index) => (
                                <circle
                                    key={`dot-${day.key}`}
                                    cx={xAt(index)}
                                    cy={lineY(day.cumulative)}
                                    r={4.5}
                                    className={signFill(day.cumulative)}
                                />
                            ))}
                        <circle
                            cx={xAt(days.length - 1)}
                            cy={lineY(total)}
                            r={showDots ? 4.5 : 4}
                            className={signFill(total)}
                        />
                        <text
                            x={xAt(days.length - 1) + 10}
                            y={lineY(total)}
                            dominantBaseline="middle"
                            className="fill-foreground text-xs font-medium tabular-nums"
                        >
                            {signed(total)}
                        </text>

                        <text
                            x={PADDING_LEFT}
                            y={barTop - 6}
                            className={axisText}
                        >
                            Daily PnL (USDT)
                        </text>
                        {barTicks.map((tick) => (
                            <g key={`bar-${tick}`}>
                                <line
                                    x1={PADDING_LEFT}
                                    x2={width - PADDING_RIGHT}
                                    y1={barY(tick)}
                                    y2={barY(tick)}
                                    className={
                                        tick === 0 ? baselineStroke : gridStroke
                                    }
                                    strokeWidth={1}
                                />
                                <text
                                    x={PADDING_LEFT - 8}
                                    y={barY(tick)}
                                    textAnchor="end"
                                    dominantBaseline="middle"
                                    className={axisText}
                                >
                                    {formatTick(tick, compactBars)}
                                </text>
                            </g>
                        ))}
                        {days.map((day, index) =>
                            day.pnl === 0 ? null : (
                                <path
                                    key={day.key}
                                    d={barPath(
                                        xAt(index) - barWidth / 2,
                                        barWidth,
                                        barY(0),
                                        barY(day.pnl),
                                    )}
                                    className={cn(
                                        day.pnl > 0 ? profitFill : lossFill,
                                        active !== null &&
                                            active !== index &&
                                            "opacity-50",
                                    )}
                                />
                            ),
                        )}

                        {days.map((day, index) =>
                            index % labelEvery === 0 ||
                            index === days.length - 1 ? (
                                <text
                                    key={`x-${day.key}`}
                                    x={xAt(index)}
                                    y={barBottom + 16}
                                    textAnchor="middle"
                                    className={axisText}
                                >
                                    {formatDay(day.date)}
                                </text>
                            ) : null,
                        )}

                        {activeDay && active !== null && (
                            <g className="pointer-events-none">
                                <line
                                    x1={xAt(active)}
                                    x2={xAt(active)}
                                    y1={lineTop}
                                    y2={barBottom}
                                    className="stroke-[#898781]"
                                    strokeWidth={1}
                                />
                                <circle
                                    cx={xAt(active)}
                                    cy={lineY(activeDay.cumulative)}
                                    r={6}
                                    className={cn(
                                        signFill(activeDay.cumulative),
                                        "stroke-card",
                                    )}
                                    strokeWidth={2}
                                />
                            </g>
                        )}

                        <rect
                            x={PADDING_LEFT}
                            y={0}
                            width={innerWidth}
                            height={barBottom}
                            fill="transparent"
                            onPointerMove={(event) =>
                                setActive(indexFromPointer(event))
                            }
                            onPointerLeave={() => setActive(null)}
                        />
                    </svg>
                )}

                {activeDay && active !== null && (
                    <div
                        className="pointer-events-none absolute top-2 z-10 w-48 rounded-md border bg-popover px-3 py-2 text-xs shadow-md"
                        style={{
                            left: Math.min(
                                Math.max(xAt(active) + 12, 0),
                                width - 192,
                            ),
                        }}
                    >
                        <p className="mb-1.5 font-medium text-muted-foreground">
                            {formatDay(activeDay.date, true)}
                        </p>
                        <div className="flex items-center justify-between gap-3">
                            <span className="flex items-center gap-1.5 text-muted-foreground">
                                <span
                                    className={cn(
                                        "h-0.5 w-3 rounded-full",
                                        activeDay.pnl < 0 ? lossBg : profitBg,
                                    )}
                                />
                                Day
                            </span>
                            <span className="font-semibold tabular-nums">
                                {signed(activeDay.pnl)}
                            </span>
                        </div>
                        <div className="flex items-center justify-between gap-3">
                            <span className="flex items-center gap-1.5 text-muted-foreground">
                                <span
                                    className={cn(
                                        "h-0.5 w-3 rounded-full",
                                        activeDay.cumulative < 0
                                            ? lossBg
                                            : profitBg,
                                    )}
                                />
                                Cumulative
                            </span>
                            <span className="font-semibold tabular-nums">
                                {signed(activeDay.cumulative)}
                            </span>
                        </div>
                        <p className="mt-1.5 text-muted-foreground">
                            {activeDay.trades}{" "}
                            {activeDay.trades === 1 ? "trade" : "trades"} closed
                        </p>
                    </div>
                )}
            </div>

            {showTable && (
                <div className="max-h-72 overflow-y-auto rounded-md border">
                    <table className="w-full text-sm">
                        <thead className="sticky top-0 bg-muted text-left text-xs text-muted-foreground">
                            <tr className="[&>th]:px-3 [&>th]:py-2 [&>th]:font-medium">
                                <th>Day</th>
                                <th className="text-right">Trades closed</th>
                                <th className="text-right">Daily PnL</th>
                                <th className="text-right">Cumulative</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-border/60">
                            {[...days].reverse().map((day) => (
                                <tr
                                    key={day.key}
                                    className="[&>td]:px-3 [&>td]:py-1.5 [&>td]:tabular-nums"
                                >
                                    <td>{formatDay(day.date, true)}</td>
                                    <td className="text-right">{day.trades}</td>
                                    <td className="text-right">
                                        {signed(day.pnl)}
                                    </td>
                                    <td className="text-right">
                                        {signed(day.cumulative)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}
