<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Grid Scanner
    |--------------------------------------------------------------------------
    |
    | Tuning values for the hourly Binance USDⓈ-M futures grid scan. The
    | scanner only reads public market data and never places an order.
    |
    */

    'symbols' => (int) env('GRID_SCAN_SYMBOLS', 100),

    // Only the highest-scoring suggestions of each scan are kept.
    'results' => (int) env('GRID_SCAN_RESULTS', 30),

    'kline_interval' => '1h',

    // Binance charges request weight 2 below 500 candles and 5 from 500 up.
    'kline_limit' => 499,

    'concurrency' => 10,

    /*
    | Daily trend filter. Daily candles classify each coin as bearish (close below a 20-day EMA
    | that is below the 50-day EMA and has fallen at least `min_trend_slope_pct` over
    | `slope_days`), bullish (the mirror) or neutral. Grids are never
    | run against a bearish or bullish daily trend, and a weaker daily tilt against the grid costs
    | up to `penalty_points`: nothing at `penalty_from_slope_pct` of 20-day EMA change over
    | `slope_days`, the full penalty at `penalty_full_slope_pct`.
    */
    'daily_trend' => [
        'kline_limit' => 90,
        'min_candles' => 55,
        'fast_ema' => 20,
        'slow_ema' => 50,
        'slope_days' => 5,
        'min_trend_slope_pct' => 1.0,
        'penalty_points' => 15,
        'penalty_from_slope_pct' => 0.5,
        'penalty_full_slope_pct' => 3.0,
    ],

    'maker_fee' => 0.0002,

    // Default planned bot runtime in days, which sizes the range, the support/resistance lookback,
    // the take profit and the trailing limit; adjustable in Settings → Grid bot.
    'range_horizon_days' => 7,

    'range_horizons' => [3, 5, 7],

    'max_leverage' => 10,

    // Share of the bot's margin the stop loss may lose at a range breakout.
    'max_stop_loss' => 0.25,

    // Default grid spacing as a multiple of the hourly ATR; adjustable in Settings → Grid bot.
    'spacing_multiplier' => 0.6,

    // Default for suggesting trailing up (Long) or trailing down (Short); adjustable in Settings → Grid bot.
    'allow_trailing' => true,

    /*
    | BTC sensitivity. A BTC shock is a sudden BTC move of `btc_shock_multiple` × BTC's daily
    | volatility. Shock hours are hours where BTC moved more than `btc_shock_hour_sigma` hourly
    | standard deviations; with fewer than `min_shock_hours` the overall beta is used instead.
    */
    'btc_shock_multiple' => 2,

    'btc_shock_hour_sigma' => 2,

    'min_shock_hours' => 5,

    // Share of a bot's margin a BTC shock may lose before leverage is lowered (keeps clear of liquidation).
    'max_shock_loss' => 0.6,

    // Share of total margin all running bots together may lose in a BTC shock before the journal warns.
    'max_portfolio_shock_loss' => 0.2,

    'min_grids' => 10,

    'max_grids' => 150,

    'min_net_grid_profit' => 0.0025,

    'keep_scans_for_days' => 7,

    /*
    |--------------------------------------------------------------------------
    | Volatile Grid Scanner
    |--------------------------------------------------------------------------
    |
    | Short bots (up to `runtime_minutes`) on pairs with a large daily ATR once they stop
    | trending and settle into a range. Runs every five minutes on 5m candles.
    |
    | A pair qualifies when its daily ATR is at least `min_daily_atr_pct` or the last 24h
    | spanned at least `min_day_range_pct`. It counts as stabilizing when the average true
    | range of the last hour is at most `max_contraction_ratio` of the busiest hour of the
    | last day, and the last `runtime_minutes` fit in a box of at most `max_box_ratio` of
    | the day's high-low range.
    |
    | The grid is centered on the start price, each side `range_sigma_min`–`range_sigma_max`
    | standard deviations of the expected move over the runtime, stretched toward the day's
    | extremes in between. Grid spacing is `spacing_multiplier` × the 5m ATR.
    |
    | Bots stay neutral unless the box drifts at least `lean_signal` standard deviations over
    | the runtime with an efficiency ratio of at least `lean_efficiency`.
    */
    'volatile' => [
        'symbols' => (int) env('GRID_VOLATILE_SYMBOLS', 150),
        'min_quote_volume' => 20_000_000,
        'results' => 15,
        'kline_limit' => 300,
        'daily_kline_limit' => 15,
        'runtime_minutes' => 120,
        'min_daily_atr_pct' => 8.0,
        'min_day_range_pct' => 15.0,
        'max_contraction_ratio' => 0.6,
        'max_box_ratio' => 0.35,
        'lean_signal' => 0.75,
        'lean_efficiency' => 0.35,
        'range_sigma_min' => 2.0,
        'range_sigma_max' => 4.0,
        'spacing_multiplier' => 0.8,
        'max_grid_spacing' => 0.015,
        'max_leverage' => 5,
        'max_stop_loss' => 0.3,
        // Share of margin the grid must be expected to earn over the runtime to be worth starting.
        'min_runtime_roi' => 0.002,
        'min_take_profit' => 0.01,
        'max_take_profit' => 0.15,
        'alert_cooldown_hours' => 2,
        'keep_scans_for_hours' => 24,
    ],

    // TradFi (tokenized equity) perpetuals are skipped by the volume ranking; these are always scanned on top of it.
    'tradfi_symbols' => [
        'SOXXUSDT', 'SOXLUSDT', 'SOXSUSDT', 'DRAMUSDT', 'IGVUSDT', 'ARTYUSDT',
        'SPYUSDT', 'QQQUSDT', 'TQQQUSDT', 'SQQQUSDT', 'UVXYUSDT', 'SPCXUSDT',
        'IBITUSDT', 'EWYUSDT', 'KORUUSDT', 'EWJUSDT', 'EWZSUSDT', 'EIDOUSDT',
    ],

    'excluded_base_assets' => ['USDC', 'FDUSD', 'TUSD', 'BUSD', 'USDP', 'DAI', 'USDE', 'EUR', 'XUSD', 'USD1'],

];
