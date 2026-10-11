<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Workforce\Application\Commands\UpdateEmployee;
use App\Support\Access;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

/** Independent HR section save used only by the guarded disposable MariaDB details races. */
require __DIR__.'/../bootstrap.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::connection()->getDatabaseName() !== 'ems_testing') {
    throw new RuntimeException('Disposable test database required.');
}
$arguments = array_map(strval(...), array_values((array) ($_SERVER['argv'] ?? [])));
if (count($arguments) !== 5) {
    throw new RuntimeException('Expected: userId, employeeId, sectionId, formPath.');
}
[, $userId, $employeeId, $sectionId, $formPath] = $arguments;
$encodedForm = file_get_contents($formPath);
if ($encodedForm === false) {
    throw new RuntimeException('Section form fixture is missing.');
}
$form = json_decode($encodedForm, true, 512, JSON_THROW_ON_ERROR);
if (! is_array($form)) {
    throw new RuntimeException('Section form fixture must be an object.');
}
auth()->setUser(User::query()->findOrFail($userId));
$app->forgetInstance(Access::class);
$actor = app(Access::class);
$ready = false;
DB::connection()->beforeExecuting(static function (string $sql) use (&$ready): void {
    $lower = strtolower($sql);
    // The command's first statement is the profile row's lock; a race test
    // holding that row learns here that this request is queued behind it.
    if (! $ready && str_contains($lower, '`employee_profiles`')
        && (str_contains($lower, 'for update') || str_starts_with($lower, 'update'))) {
        $ready = true;
        echo "ready\n";
        flush();
    }
});
try {
    app(UpdateEmployee::class)->handle($actor, $employeeId, $sectionId, $form);
    echo json_encode(['ok' => true], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    echo json_encode(['ok' => false, 'type' => $error::class, 'message' => $error->getMessage()], JSON_THROW_ON_ERROR)."\n";
}
