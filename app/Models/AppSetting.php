<?php

namespace App\Models;

use App\Enums\GridGrade;
use App\Enums\GridMode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Application-wide settings that apply to every user, such as scan tuning.
 *
 * @property string $key
 * @property string $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['key', 'value'])]
class AppSetting extends Model
{
    public const string SpacingMultiplier = 'grid.spacing_multiplier';

    public const string AllowTrailing = 'grid.allow_trailing';

    public const string RangeHorizonDays = 'grid.range_horizon_days';

    public const string MinimumGrade = 'grid.minimum_grade';

    public const string ModeFilter = 'grid.mode_filter';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * Grid spacing as a multiple of the hourly ATR, falling back to the configured default.
     */
    public static function spacingMultiplier(): float
    {
        $value = static::query()->whereKey(self::SpacingMultiplier)->value('value');

        return $value === null ? (float) config('grid.spacing_multiplier') : (float) $value;
    }

    public static function setSpacingMultiplier(float $multiplier): void
    {
        static::query()->updateOrCreate(['key' => self::SpacingMultiplier], ['value' => (string) $multiplier]);
    }

    /**
     * Planned bot runtime in days that ranges are sized for, falling back to the configured default.
     */
    public static function rangeHorizonDays(): int
    {
        $value = static::query()->whereKey(self::RangeHorizonDays)->value('value');

        return $value === null ? (int) config('grid.range_horizon_days') : (int) $value;
    }

    public static function setRangeHorizonDays(int $days): void
    {
        static::query()->updateOrCreate(['key' => self::RangeHorizonDays], ['value' => (string) $days]);
    }

    /**
     * Lowest grade a scan keeps, falling back to the configured default.
     */
    public static function minimumGrade(): GridGrade
    {
        return GridGrade::from(static::query()->whereKey(self::MinimumGrade)->value('value') ?? config('grid.minimum_grade'));
    }

    public static function setMinimumGrade(GridGrade $grade): void
    {
        static::query()->updateOrCreate(['key' => self::MinimumGrade], ['value' => $grade->value]);
    }

    /**
     * Bot type a scan keeps, or null for every type, falling back to the configured default.
     */
    public static function modeFilter(): ?GridMode
    {
        return GridMode::tryFrom(static::query()->whereKey(self::ModeFilter)->value('value') ?? config('grid.mode_filter'));
    }

    public static function setModeFilter(?GridMode $mode): void
    {
        static::query()->updateOrCreate(['key' => self::ModeFilter], ['value' => $mode->value ?? 'all']);
    }

    /**
     * Whether trend-following bots may be suggested with trailing up or trailing down.
     */
    public static function allowsTrailing(): bool
    {
        $value = static::query()->whereKey(self::AllowTrailing)->value('value');

        return $value === null ? (bool) config('grid.allow_trailing') : $value === '1';
    }

    public static function setAllowsTrailing(bool $allowed): void
    {
        static::query()->updateOrCreate(['key' => self::AllowTrailing], ['value' => $allowed ? '1' : '0']);
    }
}
