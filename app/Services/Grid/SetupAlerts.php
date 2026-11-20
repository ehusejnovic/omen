<?php

namespace App\Services\Grid;

use App\Enums\GridGrade;
use App\Enums\GridStrategy;
use App\Models\GridScan;
use App\Models\GridSuggestion;
use App\Services\Telegram\TelegramClient;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Sends a Telegram alert for every A or A+ setup of a scan, at most once a day per symbol and grade
 * for swing scans, and once per configured cooldown for the short-lived volatile setups.
 */
class SetupAlerts
{
    public const int CooldownHours = 24;

    public function __construct(private TelegramClient $telegram) {}

    /**
     * Alert on the scan's top-graded setups that were not alerted in the last day.
     */
    public function notify(GridScan $scan): void
    {
        if (! $this->telegram->isConfigured()) {
            return;
        }

        $scan->suggestions()
            ->whereIn('grade', [GridGrade::APlus, GridGrade::A])
            ->orderByDesc('score')
            ->get()
            ->each(fn (GridSuggestion $suggestion) => $this->alert($suggestion, $scan->strategy));
    }

    private function alert(GridSuggestion $suggestion, GridStrategy $strategy): void
    {
        [$key, $cooldownHours] = match ($strategy) {
            GridStrategy::Swing => ["setup-alert:{$suggestion->symbol}:{$suggestion->grade->value}", self::CooldownHours],
            GridStrategy::Volatile => ["setup-alert:volatile:{$suggestion->symbol}:{$suggestion->grade->value}", (int) config('grid.volatile.alert_cooldown_hours')],
        };

        if (! Cache::add($key, true, now()->addHours($cooldownHours))) {
            return;
        }

        try {
            $this->telegram->sendMessage($this->message($suggestion, $strategy));
        } catch (Throwable $exception) {
            Cache::forget($key);
            report($exception);
        }
    }

    private function message(GridSuggestion $suggestion, GridStrategy $strategy): string
    {
        $price = fn (float $value): string => rtrim(rtrim(number_format($value, 8, '.', ''), '0'), '.');
        $volatile = $strategy === GridStrategy::Volatile;
        $runtimeMinutes = (int) ($suggestion->metrics['runtime_minutes'] ?? config('grid.volatile.runtime_minutes'));
        $lines = [
            '<b>'.($volatile ? 'Volatile ' : '')."{$suggestion->grade->value} setup: ".e($suggestion->symbol).'</b> ('.ucfirst($suggestion->mode->value).", score {$suggestion->score})",
            "Range: {$price($suggestion->lower_price)} – {$price($suggestion->upper_price)} (last {$price($suggestion->last_price)})",
            "Grids: {$suggestion->grid_count} {$suggestion->grid_type}, ".number_format($suggestion->profit_per_grid_pct, 2).'% per grid',
            "Leverage: {$suggestion->leverage}x, margin ".number_format($suggestion->margin_allocation_pct, 0).'%',
            'TP / SL: '.number_format($suggestion->take_profit_pnl_pct, 1).'% / '.number_format($suggestion->stop_loss_pnl_pct, 1).'%',
            $volatile
                ? 'Est. ROI over '.$runtimeMinutes.' min: '.number_format((float) ($suggestion->metrics['runtime_roi_pct'] ?? 0), 2).'%'
                : 'Est. daily ROI: '.number_format($suggestion->estimated_daily_roi_pct, 2).'%',
        ];

        if ($suggestion->trailing_up || $suggestion->trailing_down) {
            $lines[] = 'Trailing '.($suggestion->trailing_up ? 'up' : 'down')
                .($suggestion->trailing_limit_price === null ? '' : ", limit {$price($suggestion->trailing_limit_price)}");
        }

        if ($suggestion->entry_trigger_price !== null) {
            $lines[] = "Entry when price {$suggestion->entry_trigger->value} {$price($suggestion->entry_trigger_price)}";
        } elseif ($volatile) {
            $lines[] = 'Start now';
        }

        if ($volatile) {
            $lines[] = "Close within {$runtimeMinutes} min";
        }

        return implode("\n", $lines);
    }
}
