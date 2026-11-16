<?php

namespace App\Console\Commands;

use App\Services\Binance\FuturesSymbolSync;
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
    public function handle(FuturesSymbolSync $symbols): int
    {
        $this->components->info('Synced '.$symbols->sync().' Binance USDT perpetual symbols.');

        return self::SUCCESS;
    }
}
