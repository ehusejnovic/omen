# Omen — Binance Futures Grid Bot Analyser

Omen scans the 100 most liquid Binance USDⓈ-M perpetual contracts every hour and suggests a complete futures grid bot configuration for each one:

- bot type: **Normal**, **Long** or **Short**
- lower and upper limit
- number of grids
- leverage
- margin
- trailing up or trailing down, with a trailing limit price
- entry trigger
- take profit and stop loss (as PnL)

Grids never run against a coin's daily trend: a coin in a daily downtrend gets a Short bot or is skipped, never a Normal one.

Every suggestion gets a quality grade from **A+** to **C**, and only the 30 best are kept. Each suggestion links straight to that pair's futures grid page on Binance. Omen also has a trade journal where you record the bots you actually ran, with a daily PnL curve.

> **Omen never trades.** It reads only public market data and does not need Binance API keys. Every figure is a rule-of-thumb estimate built from recent price history, not a backtested result or financial advice.

---

## Contents

1. [How a scan works](#how-a-scan-works)
2. [Strategy: how each parameter is chosen](#strategy-how-each-parameter-is-chosen)
3. [Scoring and grades](#scoring-and-grades)
4. [Metrics reference](#metrics-reference)
5. [Trade journal](#trade-journal)
6. [Configuration](#configuration)
7. [Local development](#local-development)
8. [Deployment](#deployment)
9. [To do](#to-do)

---

## How a scan works

A scan runs every hour on the hour, through the scheduler. You can also start one from the **Scan now** button or with `php artisan grid:scan`.

1. **Universe.** The scan fetches every contract from `/fapi/v1/exchangeInfo` and keeps the ones that are `PERPETUAL`, quoted in USDT and in `TRADING` status. Stablecoin and fiat bases (USDC, FDUSD, EUR and so on) are excluded.
2. **Ranking by liquidity.** It sorts the remaining contracts by 24h quote volume from `/fapi/v1/ticker/24hr` and takes the top 100.
3. **Market data.** For each symbol it downloads 499 one-hour candles (about 21 days) from `/fapi/v1/klines`, 10 symbols at a time. It also reads the latest funding rate of every symbol in one call to `/fapi/v1/premiumIndex`. The candle that is still open is discarded. BTCUSDT is always downloaded, even if it isn't in the top 100, because every symbol is compared with it.
4. **Analysis.** Each symbol goes through the analyser described below. A symbol with fewer than 200 closed candles, such as a new listing, is skipped. So is any setup whose range breakout would lose more than 25% of margin even at 1x (see [Leverage and stop loss](#5-leverage-and-stop-loss)), or where a sudden BTC move would lose more than 60% even at 1x (see [BTC sensitivity](#10-btc-sensitivity-and-the-btc-shock-stress-test)). Every symbol's BTC sensitivity is saved to the `symbol_stats` table, including symbols that end up with no suggestion, so the journal can use it.
5. **Selection.** The suggestions are sorted by score, with 24h volume breaking ties, and only the top 30 are stored.
6. **Margin sizing.** Each suggestion stores its margin as a percentage of the total. The scanner page multiplies it by the _Total available margin_ from **Settings → Grid bot** for whoever is viewing, so changing that setting resizes every suggestion at once. The grid spacing multiplier, also in **Settings → Grid bot**, is applied when the scan runs (see [Number of grids and grid type](#4-number-of-grids-and-grid-type)).

A full scan uses about 250 of Binance's 2,400-per-minute request weight and takes about 10 seconds. A lock ensures only one scan runs at a time. Scans older than 7 days are deleted daily.

---

## Strategy: how each parameter is chosen

All the logic lives in [`app/Services/Grid/GridAnalyzer.php`](app/Services/Grid/GridAnalyzer.php), and the indicators in [`app/Services/Grid/Indicators.php`](app/Services/Grid/Indicators.php). All tunable values are in [`config/grid.php`](config/grid.php).

Each bot is planned to run for a **range horizon** of 3, 5 or 7 days. It defaults to 7 and is set in **Settings → Grid bot**, which applies to every user. Saving a new value starts a rescan, and each scan records the horizon it used, which the scanner shows under _Last scan_. The horizon sets four things:

- how wide the range is
- how far back support and resistance are measured
- the take profit
- how far a trailing bot may follow the price

Volatility, ADX, the efficiency ratio and EMA crossings are always measured over the last 7 days, because they describe how the coin behaves, not how long the bot runs.

| Horizon | Range                                 | Money per grid                | Breakouts                |
| ------- | ------------------------------------- | ----------------------------- | ------------------------ |
| 3 days  | about 65% as wide as 7 days (√3 ÷ √7) | more, so each fill earns more | sooner, so more restarts |
| 5 days  | about 85% as wide as 7 days           | in between                    | in between               |
| 7 days  | widest                                | least                         | rarest                   |

The horizon doesn't change how often grids fill. That depends on the spacing; see [Number of grids and grid type](#4-number-of-grids-and-grid-type).

### 1. Volatility

Grid bots make money from price swinging back and forth, so everything starts from how much the price moves:

- **Daily volatility.** The standard deviation of the last 168 hourly log returns (one week), multiplied by √24.
- **Runtime volatility.** Daily volatility × √(horizon days): the typical move over the bot's planned life. It is capped at 35% so meme coins don't produce absurd ranges.
- **ATR (1h).** The average true range of the hourly candles, used to size the grid spacing and the stop buffer.

### 2. Bot type: Normal, Long or Short

| Bot type   | Chosen when                                                                                               | Behaviour on Binance                                                                   |
| ---------- | --------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------- |
| **Long**   | The market is trending (see below), the weekly drift is positive, and the 4h EMA20 is above the 4h EMA50. | The bot opens a long position at the start and sells it in steps as the price rises.   |
| **Short**  | The market is trending, the weekly drift is negative, and the 4h EMA20 is below the 4h EMA50.             | The bot opens a short position at the start and closes it in steps as the price falls. |
| **Normal** | Everything else: a sideways, choppy market.                                                               | The bot starts flat. It buys as the price falls and sells as it rises.                 |

"Trending" means **either** of these is true:

- ADX(14) on 4h candles is at least 25 **and** the 7-day efficiency ratio is at least 0.3, or
- the size of the trend signal is at least 0.4.

The trend signal is the weekly drift per day divided by daily volatility. A value of 0.4 means the price is moving about 0.4 standard deviations a day in one direction.

Requiring the EMAs to agree with the drift stops the scanner from going long into a short-lived bounce in a downtrend.

The 7-day view misses slow trends: a grind down of 0.5–1% a day with big swings inside each day looks choppy on hourly candles. So the **daily trend overrides it** (see [Daily trend filter](#11-daily-trend-filter)): a coin in a daily downtrend is never a Normal bot.

### 3. Lower and upper limit

| Bot type   | Range                                                                                                                                                                                                                                                                                                                                     |
| ---------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Normal** | Centred on the recent trading zone, from the 5th percentile of hourly lows (support) to the 95th percentile of hourly highs (resistance), measured over the range horizon. The half-width is kept between 1× and 2.5× runtime volatility. If the price is close to an edge, the range widens so the price sits inside with room to spare. |
| **Long**   | Lower limit at the support over the range horizon, but no deeper than 1.5× runtime volatility below the price. Upper limit 1.2–2.0× runtime volatility above the price, extended further when the uptrend is stronger.                                                                                                                    |
| **Short**  | The mirror image of Long.                                                                                                                                                                                                                                                                                                                 |

Both limits are rounded to the contract's tick size: the lower limit down, the upper limit up.

Centring Normal ranges on the trading zone rather than the current price is deliberate. When the price is at the top of its range, a Normal bot should wait for a pullback (see the entry trigger), not stretch its range upwards.

### 4. Number of grids and grid type

- **Target spacing** is the _spacing multiplier_ × hourly ATR. The multiplier defaults to 0.6, so a typical hourly swing completes about one grid. Spacing is never less than the round-trip fee plus 0.25% net profit (0.04% + 0.25% = 0.29% at the 0.02% maker fee), and never more than 3%.
- The **spacing multiplier** is set in **Settings → Grid bot** (0.2–1.5). It applies to every user, and saving a new value starts a rescan. Each scan records the multiplier it used, which the scanner shows under _Last scan_.
    - **Lower values** give tighter grids, more grids in the same range and more matched trades, each earning less. Fees take a larger share of each trade.
    - **Higher values** give fewer fills that are each worth more.
    - The 0.29% minimum still applies, so very low values make no difference on calm coins.
- **Grid type.** Geometric (equal percentage steps) when the upper limit is more than 1.25× the lower limit. Arithmetic (equal price steps) otherwise.
- **Number of grids** = range ÷ target spacing, kept between 10 and 150.
- **Profit per grid** = actual spacing − 2 × maker fee. This is what each completed buy and sell pair earns, as a share of the money in that grid.

### 5. Leverage and stop loss

Leverage is set by risk. The idea is that **if the price breaks out of the range and hits the stop zone, the bot loses about 25% of its margin** (`max_stop_loss`).

1. The **stop zone** is 1.5 × ATR beyond the range: below the lower limit for Long bots, above the upper limit for Short bots, and on both sides for Normal bots (the worse side counts).
2. The analyser simulates the bot's position at 1x leverage as the price travels from now to the stop zone:
    - every grid filled on the way adds a position at its grid price;
    - Long and Short bots also hold their starting position.

    It then calculates the unrealised loss. Grid profit already earned is ignored, so the estimate errs on the cautious side.

3. **Leverage** = ⌊25% ÷ loss at 1x⌋, kept between 1x and 10x. It is capped further for volatile coins: at most **5x** above 6% daily volatility and **3x** above 10%. If the loss at 1x is already above 25%, no leverage can fit the budget, so the symbol is dropped from the results.
4. **Stop loss (PnL)** = loss at 1x × the chosen leverage, shown as a negative percentage of the bot's margin. Enter it as the bot's PnL stop on Binance.

Directional bots often get 1–2x. Binance's Long and Short grids open a large position at the start, so even a modest move against them loses a lot at 1x.

### 6. Expected profit and take profit

- **Grid round trips per day** = average hourly price movement over the week ÷ spacing ÷ 2, multiplied by 0.75 because the price is not inside the range all the time.
- **Estimated daily ROI** = round trips × (leverage ÷ grids) × profit per grid, minus funding:
    - Long bots pay positive funding on about half their position.
    - Short bots receive it.
    - Normal bots are treated as having no net position.
- **Take profit (PnL)** = estimated daily ROI × horizon days. For Long and Short bots it adds half of the trend profit the bot would make if the price reached the far end of its range. It is kept between 3% and 150% of margin.

### 7. Entry trigger

The bot starts **immediately** unless the price is in a poor place to start:

| Bot type                                                            | Waits for                    | Trigger price                                            |
| ------------------------------------------------------------------- | ---------------------------- | -------------------------------------------------------- |
| Normal, price in the top 35% of the range                           | a pullback (price ≤ trigger) | 60% of the way up the range                              |
| Normal, price in the bottom 35% of the range                        | a bounce (price ≥ trigger)   | 40% of the way up the range                              |
| Long, RSI(1h) > 65 or price more than 1 ATR above the hourly EMA20  | a pullback                   | the hourly EMA20, but no more than 1 ATR below the price |
| Short, RSI(1h) < 35 or price more than 1 ATR below the hourly EMA20 | a bounce                     | the hourly EMA20, but no more than 1 ATR above the price |

Enter the trigger price in Binance's _Trigger price_ field.

### 8. Margin

Each grade has a base share of your total margin:

| Grade      | A+  | A   | B+  | B   | C+  | C   |
| ---------- | --- | --- | --- | --- | --- | --- |
| Base share | 15% | 12% | 8%  | 5%  | 3%  | 2%  |

The base share is scaled down for volatile coins by 4% ÷ daily volatility, never below half the base share. It is then rounded to the nearest 0.5%. For example, a coin with 8% daily volatility gets half its grade's base share.

The detail panel shows the **order size per grid** (margin × leverage ÷ grids). A warning appears when it falls below the contract's minimum order size on Binance (`MIN_NOTIONAL`), because Binance would reject the bot.

The shares are sizes for one bot each. With 30 suggestions they add up to well over 100%, so choose a few setups, not all of them.

---

### 9. Trailing up and trailing down

With trailing on, Binance moves the whole grid one step at a time to follow the price once it leaves the range, so the bot keeps trading instead of going idle. Trailing only helps in the direction the bot is leaning:

| Bot type   | Trailing up | Trailing down | Condition                                                                                |
| ---------- | ----------- | ------------- | ---------------------------------------------------------------------------------------- |
| **Long**   | On          | Off           | ADX(4h) between 25 and 40, trend signal ≥ 0.6, RSI(1h) ≤ 70                              |
| **Short**  | Off         | On            | ADX(4h) between 25 and 40, trend signal ≤ −0.6, RSI(1h) ≥ 30                             |
| **Normal** | Off         | Off           | Never. A Normal bot bets on the price swinging back; a breakout means the bet was wrong. |

Trailing against the bot's direction is never suggested, because it would keep adding to a losing position. An overextended trend (ADX above 40 or a stretched RSI) also doesn't get trailing, because it is likely to reverse.

When a bot trails:

- **Trailing limit price**: the upper limit raised by one runtime volatility for Long bots, or the lower limit lowered by the same amount for Short bots. Trailing stops there, so the bot can't follow a spike to its top. Enter it in Binance's trailing limit field.
- **Stop loss and leverage** assume the worst case: the grid trails all the way to the limit, then the price reverses into the shifted stop zone. The position is the same size but bought further along, so the loss at 1x is scaled by limit ÷ upper limit for Long bots (lower limit ÷ limit for Short bots), and leverage is recalculated against the 25% stop. Profit banked while trailing is ignored. If that worst case would lose more than 25% even at 1x, the bot gets no trailing.
- **Take profit** counts the trend profit out to the trailing limit instead of the range edge.

**Settings → Grid bot → Allow trailing suggestions** (on by default) turns this off for every user, so every bot runs a fixed range. Changing it starts a rescan.

### 10. BTC sensitivity and the BTC shock stress test

Most altcoins move with BTC, and a sudden BTC move breaks many grids at once. Each symbol is compared with BTC over the hours both have candles for (about 3 weeks), using hourly log returns. Code: [`app/Services/Grid/BtcSensitivity.php`](app/Services/Grid/BtcSensitivity.php).

- **Beta**: how much the coin moves for each 1% BTC moves. A beta of 1.5 means about 1.5% per 1% of BTC.
- **Correlation**: how closely the two move together, from −1 to 1. It's shown for information only, because correlations jump towards 1 in crashes, exactly when you'd want them low.
- **Shock beta (falls and rises, measured separately)**: beta measured only in hours when BTC moved more than 2 of its normal hourly moves (2 standard deviations). Alts usually fall harder with BTC than they rise with it. If there are fewer than 5 such hours in a direction, the overall beta is used instead, and the detail panel shows the number of hours used.
- **BTC shock**: a sudden BTC move of 2 × BTC's daily volatility. At around 1.6% daily volatility, that is about 3.3%.

**Stress test.** For each bot, the analyser estimates the coin's move during a BTC shock: shock beta × BTC shock. It falls for Long bots, rises for Short bots, and takes the worse of the two for Normal bots. It then simulates the bot's loss if the price jumped straight there. A sudden move can gap straight through the stop loss, so the limit here is staying clear of liquidation rather than the 25% stop: leverage is lowered until that loss is at most **60% of margin** (`max_shock_loss`). If even 1x can't meet that, the symbol is dropped. When leverage is lowered for this reason, the reasons list says so.

In calm markets this rarely binds, because the 25% stop-loss rule is stricter. It matters for narrow, high-leverage setups on high-beta coins, and when BTC volatility rises.

### 11. Daily trend filter

Daily candles (the last 90 days) classify every coin before the bot type is final. Code: [`app/Services/Grid/DailyTrend.php`](app/Services/Grid/DailyTrend.php).

- **Downtrend**: the close is below the 20-day EMA, the 20-day EMA is below the 50-day EMA, and the 20-day EMA fell at least 1% over the last 5 days.
- **Uptrend**: the mirror image.
- **No clear trend**: anything else, including coins with fewer than 55 daily candles.

A Normal bot in a downtrend fills buy orders on the way down and rarely gets the bounce to sell, so it ends up holding longs and completing few trades. A Normal bot in an uptrend has the same problem on the short side. So grids never run against the daily trend:

| Daily trend    | 4h trend (20 vs 50 EMA) | Result                                      |
| -------------- | ----------------------- | ------------------------------------------- |
| Downtrend      | down                    | **Short** bot, whatever the 7-day view says |
| Downtrend      | bouncing up             | **Skipped** until both agree                |
| Uptrend        | up                      | **Long** bot                                |
| Uptrend        | pulling back            | **Skipped** until both agree                |
| No clear trend | any                     | The 7-day rules above decide                |

A weaker tilt still costs points. When the 20-day EMA moves against the grid by more than 0.5% over 5 days without forming a full trend, the score loses up to **15 points**, reached at 3% (see [Scoring and grades](#scoring-and-grades)). Normal bots lose points for a tilt either way, and Long and Short bots only for a tilt against them.

The detail panel shows the daily trend, the 20-day EMA's 5-day change, the price against the 20-day EMA and the 30-day move. The scanner table shows the daily trend under each bot type.

## Scoring and grades

Every suggestion gets a score from 0 to 100, made up of these parts:

| Part                   | Points   | Full points when                                                                                                                                                                                                                                     |
| ---------------------- | -------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Price structure**    | 30       | **Normal:** low 7-day efficiency ratio (chop), at least 4 crossings of the hourly EMA20 per day, low ADX. **Long/Short:** ADX between 25 and 40, trend signal between 0.4 and 1.0, and RSI with room to run (below 70 for Long, above 30 for Short). |
| **Volatility**         | 20       | Daily volatility is between 2.5% and 6%. The score tapers to zero at 1.2% and at 14%.                                                                                                                                                                |
| **Grid profitability** | 20       | Estimated daily ROI is at least 1%.                                                                                                                                                                                                                  |
| **Liquidity**          | 15       | 24h volume is at least $1B. Points scale with the logarithm of volume, down to zero at $20M.                                                                                                                                                         |
| **Funding**            | 10       | Funding doesn't work against the position. The score reaches zero at 0.05% per funding interval against you. Normal bots count half of the funding in either direction.                                                                              |
| **Risk / reward**      | 5        | Take profit is at least 0.75 × the stop loss.                                                                                                                                                                                                        |
| **Daily trend tilt**   | 0 to −15 | The daily 20-day EMA moved more than 0.5% over 5 days against the grid without forming a full daily trend. The full −15 applies from 3%.                                                                                                             |
| **Penalty**            | −10      | The price moved more than 20% in the last 24h.                                                                                                                                                                                                       |

| Grade | A+   | A    | B+   | B    | C+   | C    |
| ----- | ---- | ---- | ---- | ---- | ---- | ---- |
| Score | ≥ 85 | ≥ 75 | ≥ 65 | ≥ 55 | ≥ 45 | < 45 |

The detail panel of each suggestion shows how its score breaks down, along with plain-language reasons for the setup. Its **Open on Binance** button opens that pair's futures grid page (`https://www.binance.com/en/trading-bots/futures/grid/<SYMBOL>`) in a new tab, so you can copy the settings across, and **Journal this trade** records it with the suggested settings filled in.

---

## Metrics reference

These appear in the detail panel of each suggestion.

| Metric                               | Meaning                                                                                                                      | How to read it                                                                                                            |
| ------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| **Daily volatility**                 | The typical daily move: the standard deviation of hourly returns over 7 days × √24.                                          | Grids like 2.5–6%. Below about 1% grids rarely fill; above about 10% breakouts are likely.                                |
| **ATR (1h)**                         | The average true range of one hourly candle, as a percentage of the price.                                                   | Sets the grid spacing and the stop buffer.                                                                                |
| **ADX (4h)**                         | Average Directional Index (14 periods) on 4h candles: how strong the trend is, whichever way it points.                      | Below 20: no trend. 25–40: healthy trend. Above 50: very strong or overextended.                                          |
| **Efficiency ratio (7d)**            | Net price change ÷ total distance travelled, on 4h candles over 7 days.                                                      | 0 means pure back-and-forth (ideal for Normal grids). 1 means a straight line.                                            |
| **Mean crossings / day**             | How often the hourly close crosses its 20-hour EMA each day, averaged over the week.                                         | Higher means more oscillation, so more grid fills.                                                                        |
| **RSI (1h)**                         | 14-period Relative Strength Index.                                                                                           | Above 70 is overbought, below 30 oversold. Used for the entry trigger and to keep Long and Short bots from chasing moves. |
| **Drift / day**                      | The slope of a straight line fitted to the week's log prices, per day.                                                       | The direction and pace of the trend.                                                                                      |
| **Trend signal**                     | Drift per day ÷ daily volatility.                                                                                            | Trend strength relative to the noise. A value of ±0.4 or more counts as trending.                                         |
| **Daily trend**                      | Downtrend, uptrend or no clear trend, from the daily 20- and 50-day EMAs (see [Daily trend filter](#11-daily-trend-filter)). | A grid never runs against a daily trend.                                                                                  |
| **20-day EMA, last 5 days**          | How much the daily 20-day EMA changed over 5 days.                                                                           | Beyond ±1% (with the EMAs aligned) it's a trend; beyond ±0.5% against the grid it costs points.                           |
| **Support / resistance**             | The 5th percentile of hourly lows and the 95th percentile of hourly highs over the range horizon (3, 5 or 7 days).           | The edges of the recent trading zone.                                                                                     |
| **Breakout stop zone**               | The range limits extended by 1.5 × ATR.                                                                                      | Where the stop loss PnL is expected to trigger.                                                                           |
| **Funding rate**                     | The latest funding rate per funding interval (8h or 4h, depending on the contract).                                          | Positive: longs pay shorts. Negative: shorts pay longs.                                                                   |
| **Profit per grid**                  | Grid spacing minus round-trip maker fees.                                                                                    | What one completed buy and sell pair earns.                                                                               |
| **Est. daily ROI**                   | Expected daily grid profit as a percentage of margin, after funding.                                                         | An estimate from past movement, not a guarantee.                                                                          |
| **Beta to BTC**                      | How much the coin moves for each 1% BTC moves, over about 3 weeks of hourly returns.                                         | Above 1.5: high-beta alt. Close to 0: moves on its own news.                                                              |
| **Correlation with BTC**             | How closely the coin's hourly returns follow BTC's, from −1 to 1.                                                            | Information only; it rises towards 1 in crashes.                                                                          |
| **Shock beta (BTC falls / rallies)** | Beta measured only in hours when BTC moved sharply; the number of hours used is shown in brackets.                           | A fall beta above the overall beta means the coin falls harder in sell-offs.                                              |
| **BTC shock stress test**            | Expected coin move and the bot's PnL if BTC suddenly moved by 2 × its daily volatility.                                      | Must stay within −60% of margin; leverage is lowered if not.                                                              |
| **Order size per grid**              | Margin × leverage ÷ number of grids, in USDT.                                                                                | Must be at least the contract's minimum order size on Binance.                                                            |

---

## Trade journal

**Trade journal** in the sidebar is where you record the bots you actually ran. Use **Journal trade**, or **Journal this trade** on a suggestion, which fills in the suggested settings for you.

Each entry stores:

- symbol, bot type and the grade it was suggested with
- margin, leverage, range and number of grids
- whether trailing up or trailing down was on
- the range horizon (3, 5 or 7 days, or not set), filled in from the suggestion
- **matched trades**: the completed buy/sell pairs, called the arbitrage count on Binance
- **PnL** in USDT
- start and end time, with the **duration** worked out from them (leave the end time empty while the bot is running)
- notes

The summary shows:

- **Total PnL and win rate**, counting finished trades that have a PnL.
- **Total matched trades**, counting every trade.
- **Average duration**, counting finished trades.

**PnL curve.** Finished trades with a PnL, grouped by the day they ended in your local time. Code: [`resources/js/components/pnl-curve.tsx`](resources/js/components/pnl-curve.tsx).

- The top chart is **cumulative PnL**, with the latest total labelled at the end of the line.
- The bottom chart is **daily PnL**. Profit bars are blue and go up; loss bars are red and go down, so the sign never depends on colour alone. Days without a closed trade appear as gaps.
- Hover, or focus the chart and use the arrow keys, to read a day's PnL, the cumulative total and how many trades closed. **Show table** lists the same numbers per day.

**Trades table.** Click a column header (symbol, bot, started, duration, margin, matched trades or PnL) to sort by it, and click again to reverse. It starts newest first. Trades without a PnL stay at the bottom when sorting by PnL. Sorting happens in the browser and resets on reload. There's no pagination: every trade loads at once, which suits a journal of a few thousand entries.

**By range horizon.** A table compares horizons side by side: number of trades, total PnL, win rate, average ROI (PnL ÷ margin) and matched trades per day. Total PnL, win rate and ROI count finished trades with a PnL. Matched trades per day counts every trade, running ones up to now. Trades without a horizon are grouped as _Not set_.

**BTC exposure of running bots.** When any bots are running, the journal estimates how they would react together to a sudden BTC move. Code: [`app/Services/Grid/PortfolioExposure.php`](app/Services/Grid/PortfolioExposure.php).

- **Net BTC exposure** = Σ position size (margin × leverage) × beta × direction, where Long is +1, Short is −1 and Normal is 0.
- **If BTC suddenly falls / rallies**: the estimated PnL of all running bots for a BTC shock, using each coin's shock beta.
    - Long bots count fully with the move and Short bots fully against it.
    - Normal bots count half, as long when BTC falls and as short when it rallies, because they buy on the way down and sell on the way up.
- **Warning**: shown when the worse of the two would cost more than **20% of your total margin** (`max_portfolio_shock_loss`).
- Each running trade shows its beta. Trades on symbols outside the scanned top 100 have no BTC stats, so they are listed as not included.

This is a rough, first-order estimate. It ignores how full each grid is and any profit already made.

Each user sees and edits only their own entries.

---

## Configuration

`config/grid.php`:

| Key                             | Default                                                            | Purpose                                                                                                                      |
| ------------------------------- | ------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------- |
| `symbols` (`GRID_SCAN_SYMBOLS`) | 100                                                                | How many of the most liquid perpetuals to scan.                                                                              |
| `results` (`GRID_SCAN_RESULTS`) | 30                                                                 | How many of the best-scoring suggestions to keep.                                                                            |
| `range_horizon_days`            | 7                                                                  | Default range horizon. **Settings → Grid bot** overrides it (stored in the `app_settings` table).                            |
| `range_horizons`                | 3, 5, 7                                                            | Range horizons offered in settings.                                                                                          |
| `max_leverage`                  | 10                                                                 | Overall leverage cap.                                                                                                        |
| `max_stop_loss`                 | 0.25                                                               | Share of margin the stop loss may lose at a breakout.                                                                        |
| `spacing_multiplier`            | 0.6                                                                | Default grid spacing as a multiple of hourly ATR. **Settings → Grid bot** overrides it (stored in the `app_settings` table). |
| `daily_trend.*`                 | 90 candles, 20/50 EMA, 5 days, 1% trend, 15 points from 0.5% to 3% | Daily trend filter: history fetched, EMAs, slope window, minimum slope for a trend, and the tilt penalty.                    |
| `btc_shock_multiple`            | 2                                                                  | Size of a BTC shock, as a multiple of BTC's daily volatility.                                                                |
| `btc_shock_hour_sigma`          | 2                                                                  | How sharp a BTC hour must be, in standard deviations, to count for shock beta.                                               |
| `min_shock_hours`               | 5                                                                  | Shock hours needed per direction; below this the overall beta is used.                                                       |
| `max_shock_loss`                | 0.6                                                                | Share of a bot's margin a BTC shock may lose before leverage is lowered.                                                     |
| `max_portfolio_shock_loss`      | 0.2                                                                | Share of total margin running bots may lose in a BTC shock before the journal warns.                                         |
| `allow_trailing`                | true                                                               | Default for suggesting trailing up or down. **Settings → Grid bot** overrides it (stored in the `app_settings` table).       |
| `maker_fee`                     | 0.0002                                                             | Fee per order used in the profit calculations.                                                                               |
| `min_net_grid_profit`           | 0.0025                                                             | Minimum profit per grid after fees, used for the spacing.                                                                    |
| `min_grids` / `max_grids`       | 10 / 150                                                           | Limits on the number of grids.                                                                                               |
| `keep_scans_for_days`           | 7                                                                  | How long old scans are kept.                                                                                                 |
| `excluded_base_assets`          | stablecoins and fiat                                               | Base assets that are never scanned.                                                                                          |

`config/services.php` → `binance.futures_url` (`BINANCE_FUTURES_URL`, default `https://fapi.binance.com`).

---

## Local development

Requirements: PHP 8.3+, Composer, Node 20+, MySQL 8, and Redis with the `phpredis` extension.

```bash
composer install
cp .env.example .env
php artisan key:generate
```

In `.env`, point the database at MySQL and use Redis for cache, queue and sessions:

```dotenv
DB_CONNECTION=mysql
DB_DATABASE=omen
DB_USERNAME=root
DB_PASSWORD=

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
REDIS_QUEUE_RETRY_AFTER=660
```

Then:

```bash
php artisan migrate --seed   # creates test@example.com / password
npm install && npm run build
php artisan grid:scan        # first scan, about 10 seconds
```

To keep scans and the **Scan now** button working, run these alongside the web server. With Laravel Herd the site is served at `https://omen.test`.

```bash
php artisan schedule:work    # hourly scans and daily cleanup
php artisan queue:work       # processes "Scan now"
npm run dev                  # optional, frontend hot reload
```

Tests use an in-memory SQLite database and fake every Binance request:

```bash
php artisan test --compact
composer types:check         # PHPStan
vendor/bin/pint              # code style
npm run check && npm run types:check
```

---

## Deployment

### Binance region restrictions

`fapi.binance.com` refuses requests from some regions, notably the United States (HTTP 451), and Binance may block some cloud provider IP ranges. Host Omen in a region Binance serves, and check from the server first:

```bash
curl -s https://fapi.binance.com/fapi/v1/ping   # should print {}
```

### What every environment needs

| Component                                               | Purpose                                   |
| ------------------------------------------------------- | ----------------------------------------- |
| PHP 8.3+ with `pdo_mysql`, `redis`, `mbstring`          | the application                           |
| MySQL 8 (or MariaDB 10.6+)                              | scans, suggestions, users, journal        |
| Redis                                                   | cache, the scan lock, sessions, the queue |
| **Scheduler** (`php artisan schedule:run` every minute) | hourly scans and daily cleanup            |
| **Queue worker** (`php artisan queue:work`)             | processes **Scan now**                    |
| Node 20+ at build time only                             | builds the frontend                       |

Production `.env` essentials:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://omen.example.com

DB_CONNECTION=mysql
DB_HOST=…
DB_DATABASE=omen
DB_USERNAME=…
DB_PASSWORD=…

REDIS_HOST=…
REDIS_PASSWORD=…
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
REDIS_QUEUE_RETRY_AFTER=660   # must exceed the scan job timeout (600s)
```

### Option A: a VPS (Ubuntu, Nginx, PHP-FPM)

1. **Install the code and its dependencies:**

    ```bash
    git clone <repo> /var/www/omen && cd /var/www/omen
    composer install --no-dev --optimize-autoloader
    cp .env.example .env && php artisan key:generate   # then edit .env
    npm ci && npm run build
    php artisan migrate --force
    php artisan optimize
    chown -R www-data:www-data storage bootstrap/cache
    ```

2. **Point Nginx at `public/`.** Use the standard Laravel server block with `try_files $uri $uri/ /index.php?$query_string;` and a PHP-FPM `location ~ \.php$` block. Add TLS with Certbot.

3. **Add the scheduler cron** (`crontab -u www-data -e`):

    ```cron
    * * * * * cd /var/www/omen && php artisan schedule:run >> /dev/null 2>&1
    ```

4. **Keep a queue worker running with Supervisor** (`/etc/supervisor/conf.d/omen-worker.conf`):

    ```ini
    [program:omen-worker]
    command=php /var/www/omen/artisan queue:work redis --sleep=3 --tries=1 --timeout=600 --max-time=3600
    user=www-data
    autostart=true
    autorestart=true
    stopwaitsecs=620
    redirect_stderr=true
    stdout_logfile=/var/www/omen/storage/logs/worker.log
    ```

    ```bash
    supervisorctl reread && supervisorctl update && supervisorctl start omen-worker
    ```

5. **Create a user.** The seeder's `test@example.com` / `password` account is for development only. In production, register through `/register`, or create a user with `php artisan tinker`, then remove `Features::registration()` from `config/fortify.php` if the app is private.

6. **On every deploy:**

    ```bash
    git pull
    composer install --no-dev --optimize-autoloader
    npm ci && npm run build
    php artisan migrate --force
    php artisan optimize
    php artisan queue:restart
    ```

### Option B: Laravel Cloud

1. Create an application from the repository, and attach a MySQL database and a Redis (KV) cache. Laravel Cloud fills in the connection variables.
2. Set `CACHE_STORE`, `QUEUE_CONNECTION` and `SESSION_DRIVER` to `redis`, and `REDIS_QUEUE_RETRY_AFTER=660`.
3. Build commands: `composer install --no-dev --optimize-autoloader`, `npm ci`, `npm run build`. Deploy command: `php artisan migrate --force`.
4. Turn on the **scheduler** for the app compute, and add a **queue worker** cluster (`queue:work redis --timeout=600 --tries=1`).
5. Choose a region Binance serves (see [Binance region restrictions](#binance-region-restrictions)).

### Checking a deployment

```bash
php artisan grid:scan          # should report "Scanned 100 symbols and stored 30 suggestions."
php artisan schedule:list      # grid:scan hourly, model:prune daily
```

If a scan fails, the scanner page keeps showing the last successful scan, the error is saved on the `grid_scans` row, and it is written to `storage/logs/laravel.log`.

---

## To do

- **Shock beta penalty in the score.** Take about 5–10 points off for a high fall shock beta. This is deferred until a few weeks of journal data show whether high-beta setups actually stop out more often. Today shock beta only lowers leverage (stress test) and feeds the journal's exposure panel. Watch out: a low beta isn't automatically good, because small coins often move on their own news.
- **Longer history for shock beta.** About 3 weeks of hourly candles contain only a handful of BTC shock hours, so shock beta is noisy. Fetch about 90 days of 4h candles for this measurement only.
- **BTC market regime filter.** Be more cautious with every Normal grid when BTC itself is trending hard or unusually volatile.
- **Journal pagination.** Page the trades table once the journal grows into the thousands of entries, keeping the totals, PnL curve and per-horizon table over all trades. Sorting would then move to the server.
- **Exposure in the stress test across bots.** The journal warns about combined BTC exposure, but suggestions don't yet take your running bots into account when sizing margin.
# omen
