<?php

namespace App\Jobs;

use App\Services\Grid\GridScanner;
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

    /**
     * Execute the job.
     *
     * @throws Throwable
     */
    public function handle(GridScanner $scanner): void
    {
        $scanner->scan();
    }
}
