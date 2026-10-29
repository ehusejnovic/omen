import { Form, Head } from '@inertiajs/react';
import GridSettingsController from '@/actions/App/Http/Controllers/Settings/GridSettingsController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { edit } from '@/routes/grid-settings';

export default function GridSettings({
    totalMargin,
    spacingMultiplier,
    defaultSpacingMultiplier,
    allowTrailing,
    rangeHorizonDays,
    rangeHorizonOptions,
}: {
    totalMargin: number;
    spacingMultiplier: number;
    defaultSpacingMultiplier: number;
    allowTrailing: boolean;
    rangeHorizonDays: number;
    rangeHorizonOptions: number[];
}) {
    const [trailingAllowed, setTrailingAllowed] = useState(allowTrailing);
    const [horizonDays, setHorizonDays] = useState(String(rangeHorizonDays));

    return (
        <>
            <Head title="Grid bot settings" />

            <h1 className="sr-only">Grid bot settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Grid bot settings"
                    description="Margin used to size each suggested bot and how tightly grids are spaced"
                />

                <Form
                    {...GridSettingsController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="total_margin">
                                    Total available margin (USDT)
                                </Label>

                                <Input
                                    id="total_margin"
                                    type="number"
                                    min={10}
                                    step="0.01"
                                    className="mt-1 block w-full"
                                    defaultValue={totalMargin}
                                    name="total_margin"
                                    required
                                    placeholder="1000"
                                />

                                <p className="text-sm text-muted-foreground">
                                    Each suggestion gets a share of this margin
                                    based on its grade and volatility: up to 15%
                                    for A+ setups, down to 2% for C.
                                </p>

                                <InputError
                                    className="mt-2"
                                    message={errors.total_margin}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="spacing_multiplier">
                                    Grid spacing (× hourly ATR)
                                </Label>

                                <Input
                                    id="spacing_multiplier"
                                    type="number"
                                    min={0.2}
                                    max={1.5}
                                    step="0.05"
                                    className="mt-1 block w-full"
                                    defaultValue={spacingMultiplier}
                                    name="spacing_multiplier"
                                    required
                                />

                                <p className="text-sm text-muted-foreground">
                                    Distance between grid lines as a multiple of
                                    a typical hourly candle (default{' '}
                                    {defaultSpacingMultiplier}). Lower values
                                    give tighter grids and more matched trades,
                                    each earning less. Spacing never drops below
                                    0.29%, so every grid stays at least 0.25%
                                    profitable after fees.
                                </p>

                                <p className="text-sm text-muted-foreground">
                                    This applies to every user. Saving a new
                                    value starts a rescan right away.
                                </p>

                                <InputError
                                    className="mt-2"
                                    message={errors.spacing_multiplier}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="range_horizon_days">
                                    Range horizon
                                </Label>

                                <input
                                    type="hidden"
                                    name="range_horizon_days"
                                    value={horizonDays}
                                />
                                <Select
                                    value={horizonDays}
                                    onValueChange={setHorizonDays}
                                >
                                    <SelectTrigger
                                        id="range_horizon_days"
                                        className="mt-1 w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {rangeHorizonOptions.map((days) => (
                                            <SelectItem
                                                key={days}
                                                value={String(days)}
                                            >
                                                {days} days
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>

                                <p className="text-sm text-muted-foreground">
                                    How long each bot is planned to run. It sets
                                    how wide the range is, how far back support
                                    and resistance are measured, the take
                                    profit, and how far a trailing bot may
                                    follow the price. Shorter horizons give
                                    narrower ranges with more money in each
                                    grid, but the price breaks out of them
                                    sooner. This applies to every user, and
                                    changing it starts a rescan.
                                </p>

                                <InputError
                                    className="mt-2"
                                    message={errors.range_horizon_days}
                                />
                            </div>

                            <div className="grid gap-2">
                                <input
                                    type="hidden"
                                    name="allow_trailing"
                                    value={trailingAllowed ? '1' : '0'}
                                />
                                <div className="flex items-center gap-3">
                                    <Checkbox
                                        id="allow_trailing"
                                        checked={trailingAllowed}
                                        onCheckedChange={(checked) =>
                                            setTrailingAllowed(checked === true)
                                        }
                                    />
                                    <Label htmlFor="allow_trailing">
                                        Allow trailing suggestions
                                    </Label>
                                </div>

                                <p className="text-sm text-muted-foreground">
                                    Long bots in a strong, healthy uptrend get
                                    trailing up, and Short bots in a matching
                                    downtrend get trailing down, each with a
                                    trailing limit price. Leverage and stop loss
                                    are sized for the grid trailing all the way
                                    to that limit. Normal bots never trail. This
                                    applies to every user, and changing it
                                    starts a rescan.
                                </p>

                                <InputError
                                    className="mt-2"
                                    message={errors.allow_trailing}
                                />
                            </div>

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-grid-settings-button"
                                >
                                    Save
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

GridSettings.layout = {
    breadcrumbs: [
        {
            title: 'Grid bot settings',
            href: edit(),
        },
    ],
};
