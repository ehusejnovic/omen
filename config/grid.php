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

    'excluded_base_assets' => ['USDC', 'FDUSD', 'TUSD', 'BUSD', 'USDP', 'DAI', 'USDE', 'EUR', 'XUSD', 'USD1'],

];
