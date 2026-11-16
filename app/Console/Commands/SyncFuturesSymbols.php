<?php

namespace App\Console\Commands;

use App\Models\FuturesSymbol;
use App\Services\Binance\BinanceFuturesClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

#[Signature('grid:sync-symbols')]
#[Description('Sync the list of Binance USDT perpetual symbols used by the trade journal')]
class SyncFuturesSymbols extends Command
{
    /**
     * Execute the console command.
     *
     * @throws RequestException|ConnectionException
     */
    public function handle(BinanceFuturesClient $binance): int
    {
        $contracts = $binance->perpetualContracts();

        FuturesSymbol::syncFromContracts($contracts);

        $this->components->info('Synced '.count($contracts).' Binance USDT perpetual symbols.');

        return self::SUCCESS;
    }
}
