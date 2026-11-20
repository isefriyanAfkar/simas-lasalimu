<?php

use App\Domains\People\Services\ImportSiswaService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * One cron line on the server runs `schedule:run` every minute (see
 * docs/pemasangan-hostinger.md). It starts the queue worker, which handles
 * the queued import jobs and stops when the queue is empty; while one
 * worker still runs, the next minute starts none. The mutex expires after
 * 60 minutes, so a worker killed by the server never blocks the queue for
 * longer than that.
 */
Schedule::command('queue:work', ['--stop-when-empty', '--tries=1', '--timeout='.ImportSiswaService::BATAS_WAKTU_JOB])
    ->everyMinute()
    ->withoutOverlapping(60)
    ->runInBackground();

Schedule::command('simas:bersihkan-import')
    ->everyFiveMinutes()
    ->withoutOverlapping();
