export type GridMode = 'normal' | 'long' | 'short';

export type GridGrade = 'A+' | 'A' | 'B+' | 'B' | 'C+' | 'C';

export type EntryTrigger = 'immediate' | 'below' | 'above';

export type GridScan = {
    id: number;
    symbols_scanned: number;
    suggestions_count: number;
    spacing_multiplier: number | null;
    range_horizon_days: number | null;
    started_at: string;
    finished_at: string | null;
};

export type GridSuggestion = {
    id: number;
    symbol: string;
    mode: GridMode;
    grade: GridGrade;
    score: number;
    last_price: number;
    lower_price: number;
    upper_price: number;
    range_width_pct: number;
    grid_count: number;
    grid_type: 'arithmetic' | 'geometric';
    grid_spacing_pct: number;
    profit_per_grid_pct: number;
    leverage: number;
    margin_allocation_pct: number;
    margin: number;
    notional_per_grid: number;
    trailing_up: boolean;
    trailing_down: boolean;
    trailing_limit_price: number | null;
    below_min_notional: boolean;
    entry_trigger: EntryTrigger;
    entry_trigger_price: number | null;
    take_profit_pnl_pct: number;
    take_profit_usdt: number;
    stop_loss_pnl_pct: number;
    stop_loss_usdt: number;
    estimated_daily_roi_pct: number;
    quote_volume_24h: number;
    price_change_24h_pct: number;
    funding_rate_pct: number;
    metrics: {
        daily_volatility_pct: number;
        atr_pct: number;
        adx_4h: number;
        efficiency_ratio: number;
        rsi_1h: number;
        trend_signal: number;
        drift_per_day_pct: number;
        crossings_per_day: number;
        range_horizon_days?: number;
        support: number;
        resistance: number;
        ema20_4h: number;
        ema50_4h: number;
        breakout_down_price: number;
        breakout_up_price: number;
        tick_size: number;
        min_notional: number;
        btc_beta?: number | null;
        btc_correlation?: number | null;
        btc_shock_beta_down?: number | null;
        btc_shock_beta_up?: number | null;
        btc_shock_hours_down?: number | null;
        btc_shock_hours_up?: number | null;
        btc_shock_pct?: number | null;
        btc_shock_move_pct?: number | null;
        btc_shock_loss_pct?: number | null;
        score_breakdown: Record<string, number>;
    };
    reasons: string[];
};

export type GridTrade = {
    id: number;
    symbol: string;
    mode: GridMode;
    suggested_grade: GridGrade | null;
    margin: number;
    leverage: number;
    lower_price: number | null;
    upper_price: number | null;
    grid_count: number | null;
    trailing_up: boolean;
    trailing_down: boolean;
    range_horizon_days: number | null;
    matched_trades: number;
    pnl: number | null;
    roi_pct: number | null;
    started_at: string;
    ended_at: string | null;
    duration_seconds: number;
    notes: string | null;
};

export type JournalSummary = {
    total_trades: number;
    running_trades: number;
    total_pnl: number;
    win_rate_pct: number | null;
    total_matched_trades: number;
    average_duration_seconds: number | null;
};

export type JournalExposure = {
    btc_shock_pct: number | null;
    net_beta_exposure: number;
    down_shock_pnl: number;
    up_shock_pnl: number;
    worst_shock_pnl: number;
    worst_shock_pct_of_margin: number | null;
    exceeds_limit: boolean;
    measured_trades: number;
    unmeasured_symbols: string[];
    betas: Record<number, number>;
};

export type JournalHorizonResult = {
    range_horizon_days: number | null;
    trades: number;
    closed_with_pnl: number;
    total_pnl: number;
    win_rate_pct: number | null;
    average_roi_pct: number | null;
    matched_per_day: number | null;
};
