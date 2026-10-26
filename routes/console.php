<?php

use App\Models\GridScan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('grid:scan')->hourly()->withoutOverlapping(15)->runInBackground();

Schedule::command('model:prune', ['--model' => [GridScan::class]])->daily();
