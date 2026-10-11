<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Timekeeping\Application\AssignmentConflict;
use App\Modules\Timekeeping\Application\Commands\RecordPunch;
use App\Modules\Timekeeping\Application\Commands\SaveAssignment;
use App\Modules\Timekeeping\Application\DatedChange;
use App\Modules\Timekeeping\Application\PunchInput;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

/**
 * One schedule change, or one punch, on its own connection — used only by
 * the guarded disposable MariaDB suite (ScheduleChangeConcurrencyTest). The
 * clock is frozen to the instant the test passes, so every process judges
 * "today" and the cutoff alike.
 */
require __DIR__.'/../bootstrap.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::connection()->getDatabaseName() !== 'ems_testing') {
    throw new RuntimeException('Disposable test database required.');
}
$arguments = array_map(strval(...), array_values((array) ($_SERVER['argv'] ?? [])));
if (count($arguments) !== 8 || ! in_array($arguments[1], ['assignment', 'punch'], true)) {
    throw new RuntimeException('Expected: kind, user id, employee id, pattern id, from, to, frozen instant.');
}
[, $kind, $userId, $employeeId, $patternId, $from, $to, $now] = $arguments;
CarbonImmutable::setTestNow(CarbonImmutable::parse($now));
auth()->setUser(User::query()->findOrFail($userId));
$app->forgetInstance(Access::class);
// Ready at the first locking read that can queue: the payroll membership
// row a schedule change takes first, or the employee row both take.
$announced = false;
DB::connection()->beforeExecuting(static function (string $sql) use (&$announced): void {
    $lower = strtolower($sql);
    if (! $announced && str_contains($lower, 'for update') && (str_contains($sql, '`employee_profiles`') || str_contains($sql, '`locks`'))) {
        $announced = true;
        echo "ready\n";
        flush();
    }
});
try {
    if ($kind === 'assignment') {
        app(SaveAssignment::class)->handle(app(Access::class), $employeeId, $from, $to, $patternId, 'DEFAULT', confirmed: DatedChange::ACCEPT);
        echo "saved\n";
    } else {
        $message = app(RecordPunch::class)->handle(app(Access::class), new PunchInput($employeeId, '', 'MANUAL', false, '14.5995', '120.9842'), 'TIME_IN');
        echo 'punched: '.$message."\n";
    }
} catch (AssignmentConflict $conflict) {
    echo 'overlap: '.count($conflict->conflicts)."\n";
} catch (RuntimeException $e) {
    echo 'refused: '.$e->getMessage()."\n";
}
