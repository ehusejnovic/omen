<?php

namespace App\Console\Commands;

use App\Services\Grid\GridScanner;
use App\Services\Grid\VolatileGridScanner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('grid:scan {--volatile : Look for short bots on volatile pairs that are stabilizing instead of multi-day bots}')]
#[Description('Scan the top Binance USDT perpetuals and store grid bot suggestions')]
class ScanGridOpportunities extends Command
{
    /**
     * Execute the console command.
     *
     * @throws Throwable
     */
    public function handle(GridScanner $scanner, VolatileGridScanner $volatileScanner): int
    {
        $scan = $this->option('volatile') ? $volatileScanner->scan() : $scanner->scan();

        if ($scan === null) {
            $this->components->warn('Another grid scan is already running.');

            return self::FAILURE;
        }

        $this->components->info("Scanned {$scan->symbols_scanned} symbols and stored {$scan->suggestions_count} suggestions.");

        return self::SUCCESS;
    }
}
