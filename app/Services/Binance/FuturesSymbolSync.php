<?php

namespace App\Services\Binance;

use App\Models\FuturesSymbol;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps the journal's list of Binance USDT perpetual symbols in step with the exchange.
 */
class FuturesSymbolSync
{
    /**
     * Minimum seconds between on-demand refreshes, so unknown symbols cannot hammer Binance.
     */
    private const RefreshCooldownSeconds = 600;

    public function __construct(private BinanceFuturesClient $binance) {}

    /**
     * Fetch the tradable contracts from Binance and store them, returning how many there are.
     *
     * @throws RequestException|ConnectionException
     */
    public function sync(): int
    {
        $contracts = $this->binance->perpetualContracts();

        FuturesSymbol::syncFromContracts($contracts);

        return count($contracts);
    }

    /**
     * Sync when no symbols are stored yet, or when the given symbol is unknown (it may be newly listed).
     * Binance failures are reported rather than thrown, so the journal keeps working without the list.
     */
    public function refreshWhenMissing(?string $symbol = null): void
    {
        $missing = $symbol === null
            ? ! FuturesSymbol::query()->where('is_trading', true)->exists()
            : ! FuturesSymbol::query()->whereKey($symbol)->exists();

        if (! $missing || ! Cache::add('futures-symbols:refresh', true, self::RefreshCooldownSeconds)) {
            return;
        }

        try {
            $this->sync();
        } catch (RequestException|ConnectionException $exception) {
            report($exception);
        }
    }
}
