<?php

namespace App\Console\Commands;

use App\Services\Grid\GridScanner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('grid:scan')]
#[Description('Scan the top Binance USDT perpetuals and store grid bot suggestions')]
class ScanGridOpportunities extends Command
{
    /**
     * Execute the console command.
     *
     * @throws Throwable
     */
    public function handle(GridScanner $scanner): int
    {
        $scan = $scanner->scan();

        if ($scan === null) {
            $this->components->warn('Another grid scan is already running.');

            return self::FAILURE;
        }

        $this->components->info("Scanned {$scan->symbols_scanned} symbols and stored {$scan->suggestions_count} suggestions.");

        return self::SUCCESS;
    }
}
