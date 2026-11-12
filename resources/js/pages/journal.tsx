import { Head, router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
    Pencil,
    Plus,
    Trash2,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import GridTradeController from '@/actions/App/Http/Controllers/GridTradeController';
import { GradeBadge, ModeBadge } from '@/components/grid-badges';
import GridTradeDialog from '@/components/grid-trade-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    formatDateTime,
    formatDuration,
    formatPct,
    formatUsdt,
    pnlClass,
    signedUsdt,
} from '@/lib/grid-format';
import { cn } from '@/lib/utils';
import { index as journalIndex } from '@/routes/grid-trades';
import type { GridTrade } from '@/types';

type SortKey =
    | 'symbol'
    | 'mode'
    | 'started_at'
    | 'duration_seconds'
    | 'margin'
    | 'matched_trades'
    | 'matched_profit'
    | 'pnl';

type SortDirection = 'asc' | 'desc';

/**
 * Sort trades by one column. Trades with no value in that column always go last, whichever the direction.
 */
function sortTrades(
    trades: GridTrade[],
    key: SortKey,
    direction: SortDirection,
): GridTrade[] {
    const factor = direction === 'asc' ? 1 : -1;

    return [...trades].sort((a, b) => {
        const left = a[key];
        const right = b[key];

        if (left === null || right === null) {
            return left === right ? 0 : left === null ? 1 : -1;
        }

        const order =
            typeof left === 'number' && typeof right === 'number'
                ? left - right
                : String(left).localeCompare(String(right));

        return order === 0 ? b.id - a.id : order * factor;
    });
}

function SortableHeader({
    label,
    column,
    sort,
    onSort,
    align = 'left',
}: {
    label: string;
    column: SortKey;
    sort: { key: SortKey; direction: SortDirection };
    onSort: (column: SortKey) => void;
    align?: 'left' | 'right';
}) {
    const active = sort.key === column;
    const Icon = !active
        ? ArrowUpDown
        : sort.direction === 'asc'
          ? ArrowUp
          : ArrowDown;

    return (
        <th
            aria-sort={
                active
                    ? sort.direction === 'asc'
                        ? 'ascending'
                        : 'descending'
                    : 'none'
            }
            className={cn(align === 'right' && 'text-right')}
        >
            <button
                type="button"
                onClick={() => onSort(column)}
                className={cn(
                    'inline-flex items-center gap-1 hover:text-foreground',
                    active && 'text-foreground',
                )}
            >
                {label}
                <Icon
                    className={cn('size-3', !active && 'opacity-40')}
                    aria-hidden
                />
            </button>
        </th>
    );
}

export default function Journal({
    trades,
    rangeHorizonOptions,
}: {
    trades: GridTrade[];
    rangeHorizonOptions: number[];
}) {
    const [editorOpen, setEditorOpen] = useState(false);
    const [editing, setEditing] = useState<GridTrade | null>(null);
    const [deleting, setDeleting] = useState<GridTrade | null>(null);
    const [processingDelete, setProcessingDelete] = useState(false);
    const [sort, setSort] = useState<{
        key: SortKey;
        direction: SortDirection;
    }>({ key: 'started_at', direction: 'desc' });
    const sortedTrades = useMemo(
        () => sortTrades(trades, sort.key, sort.direction),
        [trades, sort],
    );

    function toggleSort(column: SortKey) {
        setSort((current) =>
            current.key === column
                ? {
                      key: column,
                      direction: current.direction === 'asc' ? 'desc' : 'asc',
                  }
                : {
                      key: column,
                      direction:
                          column === 'symbol' || column === 'mode'
                              ? 'asc'
                              : 'desc',
                  },
        );
    }

    function openEditor(trade: GridTrade | null) {
        setEditing(trade);
        setEditorOpen(true);
    }

    function confirmDelete() {
        if (!deleting) {
            return;
        }

        router.visit(GridTradeController.destroy(deleting.id), {
            preserveScroll: true,
            onStart: () => setProcessingDelete(true),
            onFinish: () => {
                setProcessingDelete(false);
                setDeleting(null);
            },
        });
    }

    return (
        <>
            <Head title="Trade journal" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-semibold tracking-tight">
                            Trade journal
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Grid bots you've run, with matched trades, PnL,
                            duration and notes.
                        </p>
                    </div>
                    <Button onClick={() => openEditor(null)}>
                        <Plus className="size-4" />
                        Journal trade
                    </Button>
                </div>

                <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                            <tr className="[&>th]:px-3 [&>th]:py-2.5 [&>th]:font-medium [&>th]:whitespace-nowrap">
                                <SortableHeader
                                    label="Symbol"
                                    column="symbol"
                                    sort={sort}
                                    onSort={toggleSort}
                                />
                                <SortableHeader
                                    label="Bot"
                                    column="mode"
                                    sort={sort}
                                    onSort={toggleSort}
                                />
                                <SortableHeader
                                    label="Started"
                                    column="started_at"
                                    sort={sort}
                                    onSort={toggleSort}
                                />
                                <SortableHeader
                                    label="Duration"
                                    column="duration_seconds"
                                    sort={sort}
                                    onSort={toggleSort}
                                    align="right"
                                />
                                <SortableHeader
                                    label="Margin"
                                    column="margin"
                                    sort={sort}
                                    onSort={toggleSort}
                                    align="right"
                                />
                                <SortableHeader
                                    label="Matched"
                                    column="matched_trades"
                                    sort={sort}
                                    onSort={toggleSort}
                                    align="right"
                                />
                                <SortableHeader
                                    label="Matched profit"
                                    column="matched_profit"
                                    sort={sort}
                                    onSort={toggleSort}
                                    align="right"
                                />
                                <SortableHeader
                                    label="PnL"
                                    column="pnl"
                                    sort={sort}
                                    onSort={toggleSort}
                                    align="right"
                                />
                                <th>Notes</th>
                                <th className="sr-only">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-border/60">
                            {sortedTrades.map((trade) => (
                                <tr
                                    key={trade.id}
                                    className="[&>td]:px-3 [&>td]:py-2.5 [&>td]:whitespace-nowrap"
                                >
                                    <td>
                                        <div className="flex items-center gap-2 font-medium">
                                            {trade.symbol}
                                            {trade.suggested_grade && (
                                                <GradeBadge
                                                    grade={
                                                        trade.suggested_grade
                                                    }
                                                />
                                            )}
                                        </div>
                                    </td>
                                    <td>
                                        <ModeBadge mode={trade.mode} />
                                        {(trade.trailing_up ||
                                            trade.trailing_down ||
                                            trade.range_horizon_days !==
                                                null) && (
                                            <div className="mt-1 text-xs text-muted-foreground">
                                                {[
                                                    trade.range_horizon_days !==
                                                        null &&
                                                        `${trade.range_horizon_days}-day range`,
                                                    trade.trailing_up &&
                                                        'Trailing up',
                                                    trade.trailing_down &&
                                                        'Trailing down',
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </div>
                                        )}
                                    </td>
                                    <td className="tabular-nums">
                                        {formatDateTime(trade.started_at)}
                                    </td>
                                    <td className="text-right tabular-nums">
                                        {trade.ended_at === null ? (
                                            <Badge variant="secondary">
                                                Running ·{' '}
                                                {formatDuration(
                                                    trade.duration_seconds,
                                                )}
                                            </Badge>
                                        ) : (
                                            formatDuration(
                                                trade.duration_seconds,
                                            )
                                        )}
                                    </td>
                                    <td className="text-right tabular-nums">
                                        {formatUsdt(trade.margin)}
                                        <div className="text-xs text-muted-foreground">
                                            {trade.leverage}x
                                            {trade.grid_count !== null &&
                                                ` · ${trade.grid_count} grids`}
                                        </div>
                                    </td>
                                    <td className="text-right tabular-nums">
                                        {trade.matched_trades}
                                    </td>
                                    <td
                                        className={cn(
                                            'text-right tabular-nums',
                                            pnlClass(trade.matched_profit),
                                        )}
                                    >
                                        {trade.matched_profit === null
                                            ? '–'
                                            : signedUsdt(trade.matched_profit)}
                                    </td>
                                    <td
                                        className={cn(
                                            'text-right tabular-nums',
                                            pnlClass(trade.pnl),
                                        )}
                                    >
                                        {trade.pnl === null ? (
                                            '–'
                                        ) : (
                                            <>
                                                {signedUsdt(trade.pnl)}
                                                <div className="text-xs">
                                                    {formatPct(
                                                        trade.roi_pct ?? 0,
                                                        2,
                                                        true,
                                                    )}
                                                </div>
                                            </>
                                        )}
                                    </td>
                                    <td className="max-w-64 truncate text-muted-foreground">
                                        {trade.notes}
                                    </td>
                                    <td className="text-right">
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            aria-label={`Edit ${trade.symbol} trade`}
                                            onClick={() => openEditor(trade)}
                                        >
                                            <Pencil className="size-4" />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            aria-label={`Delete ${trade.symbol} trade`}
                                            onClick={() => setDeleting(trade)}
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                            {trades.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={10}
                                        className="px-3 py-12 text-center text-muted-foreground"
                                    >
                                        No trades journaled yet. Add one here or
                                        from a suggestion on the scanner.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            <GridTradeDialog
                open={editorOpen}
                onOpenChange={setEditorOpen}
                trade={editing}
                horizonOptions={rangeHorizonOptions}
            />

            <Dialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete this trade?</DialogTitle>
                        <DialogDescription>
                            The {deleting?.symbol} journal entry will be
                            permanently removed.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="ghost"
                            onClick={() => setDeleting(null)}
                        >
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={processingDelete}
                            onClick={confirmDelete}
                        >
                            Delete
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

Journal.layout = {
    breadcrumbs: [
        {
            title: 'Trade journal',
            href: journalIndex(),
        },
    ],
};
