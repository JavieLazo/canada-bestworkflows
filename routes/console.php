<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Production schedule - driven by ONE cron entry: artisan schedule:run
|--------------------------------------------------------------------------
| APP_TIMEZONE is UTC, so Manila is stated explicitly where it matters.
*/

// The reminder sweep the reference ran in the browser (GAP-07), moved to the
// server. everyThirtyMinutes rather than dailyAt, because a host with a 5- or
// 15-minute cron minimum would make dailyAt('06:00') silently never fire; the
// (user_id, dedupe_key) unique index makes extra runs a no-op.
Schedule::command('reminders:sweep')
    ->everyThirtyMinutes()
    ->between('06:00', '20:00')
    ->timezone('Asia/Manila')
    ->withoutOverlapping();

// The database cache store has no built-in prune, and the session GC lottery
// is unreliable on a low-traffic app.
Schedule::call(function (): void {
    DB::table('cache')->where('expiration', '<', now()->timestamp)->delete();
})->dailyAt('03:10')->name('prune-cache')->withoutOverlapping();

Schedule::call(function (): void {
    DB::table('sessions')
        ->where('last_activity', '<', now()->subDay()->timestamp)
        ->delete();
})->dailyAt('03:20')->name('prune-sessions')->withoutOverlapping();
