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
 * BATAS_MACET_DETIK (30 minutes), the same limit after which an import is
 * marked stuck, so a worker killed by the server never blocks the queue for
 * longer than that.
 *
 * A worker that is still busy after 30 minutes (several imports in a row)
 * loses its mutex too, and the next minute may start a second worker. That
 * is accepted: the database queue reserves each job under a row lock, so no
 * job runs twice, and imports wait for each other on the identity locks
 * (KunciIdentitas).
 */
Schedule::command('queue:work', ['--stop-when-empty', '--tries=1', '--timeout='.ImportSiswaService::BATAS_WAKTU_JOB])
    ->everyMinute()
    ->withoutOverlapping(intdiv(ImportSiswaService::BATAS_MACET_DETIK, 60))
    ->runInBackground();

/*
 * The cleanup takes seconds. Its mutex expires after 10 minutes instead of
 * the default 24 hours, so a run killed by the server holds back the
 * cleanup for at most two turns.
 */
Schedule::command('simas:bersihkan-import')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);
