<?php

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

// The month-end leave earning: each employee's approved leave policy earns
// credits for every month whose posting day has passed. The same window and
// shape as the reminder sweep, for the same reason, and idempotent by the
// (employee, month, leave type) unique key — an extra run writes nothing.
Schedule::command('leave:post-earnings')
    ->everyThirtyMinutes()
    ->between('06:00', '20:00')
    ->timezone('Asia/Manila')
    ->withoutOverlapping();

// An approved salary version dated ahead becomes the record's rates on its
// day (SalaryRates::applyDue). Idempotent: a record already at its version's
// rates is left alone, so the every-half-hour cadence is safe; the window
// opens at midnight so the day's rates are on the record before anyone
// reads it.
Schedule::command('salary:apply-due')
    ->everyThirtyMinutes()
    ->timezone('Asia/Manila')
    ->withoutOverlapping();
