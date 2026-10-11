<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Timekeeping\Application\Commands\SaveRosterDate;
use App\Modules\Timekeeping\Application\Commands\SaveRosterShifts;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

/** Independent weekly assignment used only by the guarded disposable MariaDB test. */
require __DIR__.'/../bootstrap.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::connection()->getDatabaseName() !== 'ems_testing') {
    throw new RuntimeException('Disposable test database required.');
}
$arguments = array_map(strval(...), array_values((array) ($_SERVER['argv'] ?? [])));
if (count($arguments) !== 7 || ! in_array($arguments[4], ['weekly', 'date', 'rest'], true)) {
    throw new RuntimeException('Expected user, employee and pattern ids, a roster kind, a date and the frozen instant.');
}
[, $userId, $employeeId, $patternId, $kind, $date, $now] = $arguments;
// The same frozen clock as the test, so "today" and the cutoff agree.
CarbonImmutable::setTestNow(CarbonImmutable::parse($now));
auth()->setUser(User::query()->findOrFail($userId));
$app->forgetInstance(Access::class);
// Ready at the FIRST locking read a dated change queues on: the payroll
// membership row it takes first, the pattern row, or the employee row.
$announced = false;
DB::connection()->beforeExecuting(static function (string $sql) use (&$announced): void {
    if ($announced || ! str_contains(strtolower($sql), 'for update')) {
        return;
    }
    foreach (['`locks`', '`shift_types`', '`employee_profiles`'] as $table) {
        if (str_contains($sql, $table)) {
            $announced = true;
            echo "ready\n";
            flush();

            return;
        }
    }
});
try {
    if ($kind === 'weekly') {
        app(SaveRosterShifts::class)->handle(app(Access::class), $employeeId, [1 => $patternId]);
    } else {
        app(SaveRosterDate::class)->handle(app(Access::class), $employeeId, $date, $kind === 'rest' ? 'REST' : $patternId, 'DEFAULT');
    }
    echo "saved\n";
} catch (RuntimeException $e) {
    echo 'refused: '.$e->getMessage()."\n";
}
