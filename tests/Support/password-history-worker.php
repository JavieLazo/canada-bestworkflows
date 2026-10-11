<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\IdentityAccess\Application\Commands\ChangeOwnPassword;
use App\Support\Access;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Competes for a credential change using synthetic passwords on the guarded disposable database. */
require __DIR__.'/../bootstrap.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::connection()->getDatabaseName() !== 'ems_testing') {
    throw new RuntimeException('Disposable test database required.');
}
$arguments = array_values((array) ($_SERVER['argv'] ?? []));
if (count($arguments) !== 2) {
    throw new RuntimeException('Expected an account ID.');
}
$user = User::query()->findOrFail((string) $arguments[1]);
$ready = false;
DB::connection()->beforeExecuting(static function (string $sql) use (&$ready): void {
    if (! $ready && str_contains(strtolower($sql), 'for update')) {
        $ready = true;
        echo "ready\n";
        flush();
    }
});
try {
    app(ChangeOwnPassword::class)->handle(Access::forActor($user), 'Original-safe-2026!', 'Losing-password-2026!', 'Losing-password-2026!', 'competing');
    echo json_encode(['stale_current_rejected' => false], JSON_THROW_ON_ERROR)."\n";
} catch (ValidationException $error) {
    echo json_encode(['stale_current_rejected' => isset($error->errors()['current_password'])], JSON_THROW_ON_ERROR)."\n";
}
