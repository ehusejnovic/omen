<?php

namespace App\Services\Binance;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Read-only client for the public Binance USDⓈ-M futures market data endpoints.
 */
class BinanceFuturesClient
{
    /**
     * Tradable USDT-margined perpetual contracts keyed by symbol.
     *
     * @return array<string, array{base_asset: string, tick_size: float, min_notional: float}>
     *
     * @throws RequestException|ConnectionException
     */
    public function perpetualContracts(): array
    {
        $contracts = [];

        foreach ($this->request()->get('/fapi/v1/exchangeInfo')->throw()->collect('symbols') as $symbol) {
            if ($symbol['contractType'] !== 'PERPETUAL' || $symbol['quoteAsset'] !== 'USDT' || $symbol['status'] !== 'TRADING') {
                continue;
            }

            $filters = array_column($symbol['filters'], null, 'filterType');

            $contracts[$symbol['symbol']] = [
                'base_asset' => $symbol['baseAsset'],
                'tick_size' => (float) ($filters['PRICE_FILTER']['tickSize'] ?? 0),
                'min_notional' => (float) ($filters['MIN_NOTIONAL']['notional'] ?? 5),
            ];
        }

        return $contracts;
    }

    /**
     * 24 hour ticker statistics keyed by symbol.
     *
     * @return array<string, array{last_price: float, quote_volume: float, price_change_pct: float}>
     *
     * @throws RequestException|ConnectionException
     */
    public function tickers(): array
    {
        return $this->request()->get('/fapi/v1/ticker/24hr')->throw()->collect()
            ->mapWithKeys(fn (array $ticker): array => [$ticker['symbol'] => [
                'last_price' => (float) $ticker['lastPrice'],
                'quote_volume' => (float) $ticker['quoteVolume'],
                'price_change_pct' => (float) $ticker['priceChangePercent'],
            ]])
            ->all();
    }

    /**
     * Latest funding rate per 8h interval keyed by symbol.
     *
     * @return array<string, float>
     *
     * @throws RequestException|ConnectionException
     */
    public function fundingRates(): array
    {
        return $this->request()->get('/fapi/v1/premiumIndex')->throw()->collect()
            ->mapWithKeys(fn (array $index): array => [$index['symbol'] => (float) $index['lastFundingRate']])
            ->all();
    }

    /**
     * Closed candles for several symbols, fetched concurrently. Symbols whose request fails are omitted.
     *
     * @param  list<string>  $symbols
     * @param  positive-int  $concurrency
     * @return array<string, list<array{open_time: int, open: float, high: float, low: float, close: float}>>
     */
    public function closedCandles(array $symbols, string $interval, int $limit, int $concurrency): array
    {
        $candles = [];

        foreach (array_chunk($symbols, $concurrency) as $chunk) {
            $responses = Http::pool(function (Pool $pool) use ($chunk, $interval, $limit): void {
                foreach ($chunk as $symbol) {
                    $this->configure($pool->as($symbol))
                        ->get('/fapi/v1/klines', ['symbol' => $symbol, 'interval' => $interval, 'limit' => $limit]);
                }
            });

            foreach ($responses as $symbol => $response) {
                if (! $response instanceof Response || ! $response->successful()) {
                    continue;
                }

                $candles[$symbol] = $this->parseCandles($response->json());
            }
        }

        return $candles;
    }

    /**
     * Normalize raw kline rows, dropping the candle that is still open.
     *
     * @param  list<list<int|string>>  $rows
     * @return list<array{open_time: int, open: float, high: float, low: float, close: float}>
     */
    private function parseCandles(array $rows): array
    {
        $nowInMilliseconds = now()->getTimestampMs();
        $candles = [];

        foreach ($rows as $row) {
            if ((int) $row[6] >= $nowInMilliseconds) {
                continue;
            }

            $candles[] = [
                'open_time' => (int) $row[0],
                'open' => (float) $row[1],
                'high' => (float) $row[2],
                'low' => (float) $row[3],
                'close' => (float) $row[4],
            ];
        }

        return $candles;
    }

    private function request(): PendingRequest
    {
        return $this->configure(Http::acceptJson())
            ->retry([250, 1000, 3000], 0, fn (Throwable $exception): bool => $exception instanceof ConnectionException
                || ($exception instanceof RequestException
                    && ($exception->response->serverError() || $exception->response->status() === 429)));
    }

    private function configure(PendingRequest $request): PendingRequest
    {
        return $request
            ->baseUrl(config('services.binance.futures_url'))
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(20);
    }
}
