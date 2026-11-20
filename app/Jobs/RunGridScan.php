<?php

namespace App\Jobs;

use App\Enums\GridStrategy;
use App\Services\Grid\GridScanner;
use App\Services\Grid\VolatileGridScanner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Throwable;

#[Tries(1)]
#[Timeout(600)]
#[UniqueFor(600)]
class RunGridScan implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public GridStrategy $strategy = GridStrategy::Swing) {}

    /**
     * Keep one queued scan per strategy.
     */
    public function uniqueId(): string
    {
        return $this->strategy->value;
    }

    /**
     * Execute the job.
     *
     * @throws Throwable
     */
    public function handle(GridScanner $scanner, VolatileGridScanner $volatileScanner): void
    {
        match ($this->strategy) {
            GridStrategy::Swing => $scanner->scan(),
            GridStrategy::Volatile => $volatileScanner->scan(),
        };
    }
}
