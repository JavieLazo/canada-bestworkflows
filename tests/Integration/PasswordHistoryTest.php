<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\UserPasswordHistory;
use App\Modules\IdentityAccess\Application\Commands\ChangeOwnPassword;
use App\Modules\IdentityAccess\Infrastructure\PasswordWriter;
use App\Support\Access;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

/** Credential history, password replacement and session revocation remain atomic under MariaDB locks. */
it('resumes password history schema changes without altering existing credentials or history', function () {
    expect(DB::selectOne('select version() as version')->version)->toContain('11.4.')->toContain('MariaDB');
    $migration = require base_path('database/migrations/2026_10_08_0008_create_user_password_histories_table.php');
    $migration->down();
    $migration->down();
    $migration->up();
    $migration->up();
    $user = User::factory()->create(['password' => 'Original-safe-2026!']);
    app(PasswordWriter::class)->replace($user, 'Replacement-safe-2026!');
    $hash = $user->fresh()->password;
    $history = UserPasswordHistory::query()->sole();
    Schema::table('user_password_histories', static fn ($table) => $table->dropForeign('uph_user_fk'));
    Schema::table('user_password_histories', static fn ($table) => $table->dropIndex('uph_user_idx'));
    $migration->up();
    $migration->up();
    expect(Schema::hasIndex('user_password_histories', 'uph_user_idx'))->toBeTrue()
        ->and(Schema::hasForeignKey('user_password_histories', ['user_id']))->toBeTrue()
        ->and($user->fresh()->password)->toBe($hash)
        ->and(UserPasswordHistory::query()->sole()->getKey())->toBe($history->getKey())
        ->and($history->toArray())->not->toHaveKey('password_hash');
    expect(fn () => (new UserPasswordHistory)->forceFill([
        'user_id' => 99999999, 'password_hash' => $hash,
    ])->save())->toThrow(QueryException::class);
    $user->delete();
    expect(UserPasswordHistory::query()->count())->toBe(0);
});

it('rolls back history credentials and tokens when session revocation fails', function () {
    $user = User::factory()->create(['password' => 'Original-safe-2026!', 'remember_token' => 'original-token']);
    DB::table('sessions')->insert(['id' => 'password-history-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
    $hash = $user->password;
    DB::unprepared("CREATE TRIGGER reject_password_session_delete BEFORE DELETE ON sessions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic session failure'");
    try {
        expect(fn () => app(PasswordWriter::class)->replace($user, 'Replacement-safe-2026!'))
            ->toThrow(RuntimeException::class, 'The password could not be changed.');
        expect($user->fresh()->password)->toBe($hash)
            ->and($user->fresh()->remember_token)->toBe('original-token')
            ->and(UserPasswordHistory::query()->count())->toBe(0)
            ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1);
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS reject_password_session_delete');
    }
    app(PasswordWriter::class)->replace($user, 'Replacement-safe-2026!');
    expect(Hash::check('Replacement-safe-2026!', $user->fresh()->password))->toBeTrue()
        ->and(UserPasswordHistory::query()->sole()->getAttribute('password_hash'))->toBe($hash)
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
});

it('rejects a competing self change when its current password becomes stale under the account lock', function () {
    $user = User::factory()->create(['password' => 'Original-safe-2026!']);
    $environment = [
        'APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'),
        'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'ems_testing', 'DB_USERNAME' => 'ems_testing',
        'DB_HOST' => '127.0.0.1', 'DB_PORT' => (string) DB::connection()->getConfig('port'),
        'DB_PASSWORD' => (string) DB::connection()->getConfig('password'), 'DB_URL' => '', 'DB_SOCKET' => '',
        'EMS_MARIADB_TESTS' => 'disposable', 'BROADCAST_CONNECTION' => 'null', 'MAIL_MAILER' => 'array',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'null', 'QUEUE_CONNECTION' => 'sync',
    ];
    $worker = new Process([PHP_BINARY, base_path('tests/Support/password-history-worker.php'), (string) $user->id], base_path(), $environment, timeout: 20);
    DB::beginTransaction();
    try {
        User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
        $worker->start();
        expect($worker->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'ready')))->toBeTrue();
        app(ChangeOwnPassword::class)->handle(Access::forActor($user), 'Original-safe-2026!', 'Winner-password-2026!', 'Winner-password-2026!', 'retained');
        DB::commit();
        $worker->wait();
        expect($worker->getExitCode())->toBe(0)
            ->and($worker->getOutput())->toContain('"stale_current_rejected":true')
            ->and(Hash::check('Winner-password-2026!', $user->fresh()->password))->toBeTrue()
            ->and(UserPasswordHistory::query()->where('user_id', $user->id)->count())->toBe(1);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if ($worker->isRunning()) {
            $worker->stop();
        }
    }
});
