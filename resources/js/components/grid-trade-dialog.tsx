import { useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import GridTradeController from '@/actions/App/Http/Controllers/GridTradeController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { fromLocalInput, toLocalInput } from '@/lib/grid-format';
import type { GridGrade, GridMode, GridSuggestion, GridTrade } from '@/types';

type TradeForm = {
    symbol: string;
    mode: GridMode;
    suggested_grade: GridGrade | '';
    margin: string;
    leverage: string;
    lower_price: string;
    upper_price: string;
    grid_count: string;
    trailing_up: boolean;
    trailing_down: boolean;
    range_horizon_days: string;
    matched_trades: string;
    pnl: string;
    started_at: string;
    ended_at: string;
    notes: string;
};

function text(value: number | string | null | undefined): string {
    return value === null || value === undefined ? '' : String(value);
}

function initialValues(
    trade: GridTrade | null,
    suggestion: GridSuggestion | null,
): TradeForm {
    if (trade) {
        return {
            symbol: trade.symbol,
            mode: trade.mode,
            suggested_grade: trade.suggested_grade ?? '',
            margin: text(trade.margin),
            leverage: text(trade.leverage),
            lower_price: text(trade.lower_price),
            upper_price: text(trade.upper_price),
            grid_count: text(trade.grid_count),
            trailing_up: trade.trailing_up,
            trailing_down: trade.trailing_down,
            range_horizon_days: text(trade.range_horizon_days),
            matched_trades: text(trade.matched_trades),
            pnl: text(trade.pnl),
            started_at: toLocalInput(trade.started_at),
            ended_at: trade.ended_at ? toLocalInput(trade.ended_at) : '',
            notes: trade.notes ?? '',
        };
    }

    return {
        symbol: suggestion?.symbol ?? '',
        mode: suggestion?.mode ?? 'normal',
        suggested_grade: suggestion?.grade ?? '',
        margin: text(suggestion?.margin),
        leverage: text(suggestion?.leverage),
        lower_price: text(suggestion?.lower_price),
        upper_price: text(suggestion?.upper_price),
        grid_count: text(suggestion?.grid_count),
        trailing_up: suggestion?.trailing_up ?? false,
        trailing_down: suggestion?.trailing_down ?? false,
        range_horizon_days: text(suggestion?.metrics.range_horizon_days),
        matched_trades: '0',
        pnl: '',
        started_at: toLocalInput(new Date().toISOString()),
        ended_at: '',
        notes: '',
    };
}

function Field({
    label,
    htmlFor,
    error,
    hint,
    children,
}: {
    label: string;
    htmlFor: string;
    error?: string;
    hint?: string;
    children: ReactNode;
}) {
    return (
        <div className="grid gap-1.5">
            <Label htmlFor={htmlFor}>{label}</Label>
            {children}
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
            <InputError message={error} />
        </div>
    );
}

function TradeFormFields({
    trade,
    suggestion,
    horizonOptions,
    onDone,
}: {
    trade: GridTrade | null;
    suggestion: GridSuggestion | null;
    horizonOptions: number[];
    onDone: () => void;
}) {
    const form = useForm<TradeForm>(initialValues(trade, suggestion));
    const { data, setData, errors, processing } = form;

    form.transform((values) => ({
        ...values,
        suggested_grade: values.suggested_grade || null,
        range_horizon_days: values.range_horizon_days || null,
        lower_price: values.lower_price || null,
        upper_price: values.upper_price || null,
        grid_count: values.grid_count || null,
        pnl: values.pnl || null,
        started_at: fromLocalInput(values.started_at),
        ended_at: fromLocalInput(values.ended_at),
        notes: values.notes || null,
    }));

    function submit(event: FormEvent) {
        event.preventDefault();

        form.submit(
            trade
                ? GridTradeController.update(trade.id)
                : GridTradeController.store(),
            { preserveScroll: true, onSuccess: onDone },
        );
    }

    return (
        <form onSubmit={submit} className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-3">
                <Field label="Symbol" htmlFor="symbol" error={errors.symbol}>
                    <Input
                        id="symbol"
                        value={data.symbol}
                        onChange={(event) =>
                            setData('symbol', event.target.value)
                        }
                        placeholder="BTCUSDT"
                        required
                    />
                </Field>
                <Field label="Bot" htmlFor="mode" error={errors.mode}>
                    <Select
                        value={data.mode}
                        onValueChange={(value) =>
                            setData('mode', value as GridMode)
                        }
                    >
                        <SelectTrigger id="mode">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="normal">Normal</SelectItem>
                            <SelectItem value="long">Long</SelectItem>
                            <SelectItem value="short">Short</SelectItem>
                        </SelectContent>
                    </Select>
                </Field>
                <Field
                    label="Suggested grade"
                    htmlFor="suggested_grade"
                    error={errors.suggested_grade}
                >
                    <Select
                        value={data.suggested_grade || 'none'}
                        onValueChange={(value) =>
                            setData(
                                'suggested_grade',
                                value === 'none' ? '' : (value as GridGrade),
                            )
                        }
                    >
                        <SelectTrigger id="suggested_grade">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none">None</SelectItem>
                            {(['A+', 'A', 'B+', 'B', 'C+', 'C'] as const).map(
                                (grade) => (
                                    <SelectItem key={grade} value={grade}>
                                        {grade}
                                    </SelectItem>
                                ),
                            )}
                        </SelectContent>
                    </Select>
                </Field>
            </div>

            <div className="grid gap-4 sm:grid-cols-3">
                <Field
                    label="Margin (USDT)"
                    htmlFor="margin"
                    error={errors.margin}
                >
                    <Input
                        id="margin"
                        type="number"
                        step="0.01"
                        min="0"
                        value={data.margin}
                        onChange={(event) =>
                            setData('margin', event.target.value)
                        }
                        required
                    />
                </Field>
                <Field
                    label="Leverage"
                    htmlFor="leverage"
                    error={errors.leverage}
                >
                    <Input
                        id="leverage"
                        type="number"
                        min="1"
                        max="125"
                        value={data.leverage}
                        onChange={(event) =>
                            setData('leverage', event.target.value)
                        }
                        required
                    />
                </Field>
                <Field
                    label="Grids"
                    htmlFor="grid_count"
                    error={errors.grid_count}
                >
                    <Input
                        id="grid_count"
                        type="number"
                        min="2"
                        value={data.grid_count}
                        onChange={(event) =>
                            setData('grid_count', event.target.value)
                        }
                    />
                </Field>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    label="Lower limit"
                    htmlFor="lower_price"
                    error={errors.lower_price}
                >
                    <Input
                        id="lower_price"
                        type="number"
                        step="any"
                        min="0"
                        value={data.lower_price}
                        onChange={(event) =>
                            setData('lower_price', event.target.value)
                        }
                    />
                </Field>
                <Field
                    label="Upper limit"
                    htmlFor="upper_price"
                    error={errors.upper_price}
                >
                    <Input
                        id="upper_price"
                        type="number"
                        step="any"
                        min="0"
                        value={data.upper_price}
                        onChange={(event) =>
                            setData('upper_price', event.target.value)
                        }
                    />
                </Field>
            </div>

            <div className="flex flex-wrap gap-6">
                <div className="flex items-center gap-2">
                    <Checkbox
                        id="trailing_up"
                        checked={data.trailing_up}
                        onCheckedChange={(checked) =>
                            setData('trailing_up', checked === true)
                        }
                    />
                    <Label htmlFor="trailing_up">Trailing up</Label>
                </div>
                <div className="flex items-center gap-2">
                    <Checkbox
                        id="trailing_down"
                        checked={data.trailing_down}
                        onCheckedChange={(checked) =>
                            setData('trailing_down', checked === true)
                        }
                    />
                    <Label htmlFor="trailing_down">Trailing down</Label>
                </div>
            </div>

            <Field
                label="Range horizon"
                htmlFor="range_horizon_days"
                error={errors.range_horizon_days}
                hint="The horizon the bot's range was sized for, so you can compare horizons"
            >
                <Select
                    value={data.range_horizon_days || 'none'}
                    onValueChange={(value) =>
                        setData(
                            'range_horizon_days',
                            value === 'none' ? '' : value,
                        )
                    }
                >
                    <SelectTrigger id="range_horizon_days">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="none">Not set</SelectItem>
                        {horizonOptions.map((days) => (
                            <SelectItem key={days} value={String(days)}>
                                {days} days
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </Field>

            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    label="Matched trades"
                    htmlFor="matched_trades"
                    error={errors.matched_trades}
                    hint="Completed buy/sell pairs (arbitrage count)"
                >
                    <Input
                        id="matched_trades"
                        type="number"
                        min="0"
                        value={data.matched_trades}
                        onChange={(event) =>
                            setData('matched_trades', event.target.value)
                        }
                        required
                    />
                </Field>
                <Field
                    label="PnL (USDT)"
                    htmlFor="pnl"
                    error={errors.pnl}
                    hint="Total profit or loss, negative for a loss"
                >
                    <Input
                        id="pnl"
                        type="number"
                        step="0.01"
                        value={data.pnl}
                        onChange={(event) => setData('pnl', event.target.value)}
                    />
                </Field>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    label="Started"
                    htmlFor="started_at"
                    error={errors.started_at}
                >
                    <Input
                        id="started_at"
                        type="datetime-local"
                        value={data.started_at}
                        onChange={(event) =>
                            setData('started_at', event.target.value)
                        }
                        required
                    />
                </Field>
                <Field
                    label="Ended"
                    htmlFor="ended_at"
                    error={errors.ended_at}
                    hint="Leave empty while the bot is still running"
                >
                    <Input
                        id="ended_at"
                        type="datetime-local"
                        value={data.ended_at}
                        onChange={(event) =>
                            setData('ended_at', event.target.value)
                        }
                    />
                </Field>
            </div>

            <Field label="Notes" htmlFor="notes" error={errors.notes}>
                <textarea
                    id="notes"
                    rows={3}
                    value={data.notes}
                    onChange={(event) => setData('notes', event.target.value)}
                    className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    placeholder="Why you took it, what happened, lessons…"
                />
            </Field>

            <DialogFooter>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
                <Button type="submit" disabled={processing}>
                    {trade ? 'Save changes' : 'Journal trade'}
                </Button>
            </DialogFooter>
        </form>
    );
}

export default function GridTradeDialog({
    open,
    onOpenChange,
    trade = null,
    suggestion = null,
    horizonOptions,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    trade?: GridTrade | null;
    suggestion?: GridSuggestion | null;
    horizonOptions: number[];
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {trade
                            ? `Edit ${trade.symbol} trade`
                            : 'Journal a trade'}
                    </DialogTitle>
                    <DialogDescription>
                        Record a grid bot you ran on Binance.
                    </DialogDescription>
                </DialogHeader>
                {open && (
                    <TradeFormFields
                        key={trade?.id ?? suggestion?.id ?? 'new'}
                        trade={trade}
                        suggestion={suggestion}
                        horizonOptions={horizonOptions}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}
