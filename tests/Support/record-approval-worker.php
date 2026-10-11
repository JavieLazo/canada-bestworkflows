<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Workforce\Application\Commands\ReviewRecordChange;
use App\Support\Access;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

/** Independent reviewer used only for the guarded disposable MariaDB approval race. */
require __DIR__.'/../bootstrap.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::connection()->getDatabaseName() !== 'ems_testing') {
    throw new RuntimeException('Disposable test database required.');
}
$arguments = array_map(strval(...), array_values((array) ($_SERVER['argv'] ?? [])));
if (count($arguments) !== 3) {
    throw new RuntimeException('Expected: reviewerId, requestId.');
}
[, $reviewerId, $requestId] = $arguments;
auth()->setUser(User::query()->findOrFail($reviewerId));
$app->forgetInstance(Access::class);
$actor = app(Access::class);
$ready = false;
DB::connection()->beforeExecuting(static function (string $sql) use (&$ready): void {
    if (! $ready && str_contains(strtolower($sql), 'for update')) {
        $ready = true;
        echo "ready\n";
        flush();
    }
});
try {
    app(ReviewRecordChange::class)->handle($actor, $requestId, true);
    echo json_encode(['ok' => true], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    echo json_encode(['ok' => false, 'type' => $error::class, 'message' => $error->getMessage()], JSON_THROW_ON_ERROR)."\n";
}
