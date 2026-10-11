<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Timekeeping\Application\Commands\FileAbsence;
use App\Support\Access;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

/** Independent filing connection used only by the guarded disposable MariaDB tests. */
require __DIR__.'/../bootstrap.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::connection()->getDatabaseName() !== 'ems_testing') {
    throw new RuntimeException('Disposable test database required.');
}
$arguments = array_map(strval(...), array_values((array) ($_SERVER['argv'] ?? [])));
if (count($arguments) !== 3 || ! in_array($arguments[2], ['leave_credits', 'leave_travel_requests'], true)) {
    throw new RuntimeException('Expected a user id and a leave locking table.');
}
[, $userId, $table] = $arguments;
auth()->setUser(User::query()->findOrFail($userId));
$app->forgetInstance(Access::class);
DB::connection()->beforeExecuting(static function (string $sql) use ($table): void {
    if (str_contains($sql, '`'.$table.'`') && str_contains(strtolower($sql), 'for update')) {
        echo "ready\n";
        flush();
    }
});
try {
    app(FileAbsence::class)->handle(app(Access::class), 'VACATION_LEAVE', 'WHOLE_DAY', '2026-09-28', '2026-09-28', '');
    echo json_encode(['filed' => true], JSON_THROW_ON_ERROR)."\n";
} catch (RuntimeException $e) {
    echo json_encode(['filed' => false, 'type' => $e::class, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR)."\n";
}
