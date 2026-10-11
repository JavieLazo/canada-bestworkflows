<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Workforce\Application\Commands\UpdateEmployee;
use App\Support\Access;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

/** Independent HR request used only by the guarded disposable MariaDB history races. */
require __DIR__.'/../bootstrap.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::connection()->getDatabaseName() !== 'ems_testing') {
    throw new RuntimeException('Disposable test database required.');
}
$arguments = array_map(strval(...), array_values((array) ($_SERVER['argv'] ?? [])));
if (count($arguments) !== 5 || ! in_array($arguments[4], ['structure', 'row'], true)) {
    throw new RuntimeException('Expected: userId, employeeId, formPath, structure|row.');
}
[, $userId, $employeeId, $formPath, $heldLocks] = $arguments;
// With only the row held, the structure mutex is free: taking it is no wait,
// so the signal belongs to the row lock alone.
$watchLocks = $heldLocks === 'structure';
$encodedForm = file_get_contents($formPath);
if ($encodedForm === false) {
    throw new RuntimeException('Employment form fixture is missing.');
}
$form = json_decode($encodedForm, true, 512, JSON_THROW_ON_ERROR);
if (! is_array($form)) {
    throw new RuntimeException('Employment form fixture must be an object.');
}
auth()->setUser(User::query()->findOrFail($userId));
$app->forgetInstance(Access::class);
$actor = app(Access::class);
$ready = false;
DB::connection()->beforeExecuting(static function (string $sql) use (&$ready, $watchLocks): void {
    $lower = strtolower($sql);
    // A correct command queues before reading the employee. The old command
    // reached its UPDATE with a stale model; both paths wait behind the locks
    // held by the test — the row, and the structure mutex when the test holds
    // it too — so the regression needs no timing assumptions.
    if (! $ready && (str_contains($lower, '`employee_profiles`') || ($watchLocks && str_contains($lower, '`locks`')))
        && (str_contains($lower, 'for update') || str_starts_with($lower, 'update'))) {
        $ready = true;
        echo "ready\n";
        flush();
    }
});
try {
    app(UpdateEmployee::class)->handle($actor, $employeeId, 'employment', $form);
    echo json_encode(['ok' => true], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    echo json_encode(['ok' => false, 'type' => $error::class, 'message' => $error->getMessage()], JSON_THROW_ON_ERROR)."\n";
}
