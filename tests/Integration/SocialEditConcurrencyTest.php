<?php

declare(strict_types=1);

use App\Models\ChatMessage;
use App\Models\CommunityPost;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Social\Application\Commands\EditMessage;
use App\Modules\Social\Application\Commands\EditPost;
use App\Modules\Social\Application\Commands\SendMessage;
use App\Modules\Social\Application\Commands\StartConversation;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * On the production engine an edit never loses or undoes another writer's
 * committed change. Two saves of one post queue on the post's row lock, and
 * the one that waited refuses instead of writing its stale copy over the
 * first, so the post's history keeps every version. An edit of a chat
 * message only writes while the message is still there, so a removal that
 * another connection commits first keeps the text gone.
 *
 * sqlite has no row locks and one connection, so only this suite sees the
 * real waiting and the real committed rows.
 */
function socraceUser(string $name, string $module): User
{
    $role = new Role;
    $role->forceFill(['name' => 'Social race '.Str::random(6), 'all_data' => false, 'is_system' => false])->save();
    $grant = new RoleModuleGrant;
    $grant->forceFill(['role_id' => $role->id, 'module' => $module])->save();
    $employee = new EmployeeProfile;
    $employee->forceFill(['employee_code' => 'SOCRACE-'.Str::random(8), 'first_name' => Str::before($name, ' '),
        'last_name' => Str::after($name, ' '), 'date_hired' => '2024-01-01', 'employment_status' => 'Regular',
        'is_active' => true])->save();

    return User::factory()->create(['role_id' => $role->id, 'employee_id' => $employee->id, 'is_active' => true]);
}

it('makes a second save of a post wait on the row lock, then refuse, so the history keeps every version', function () {
    $author = socraceUser('Kim Reyes', 'COMMUNITY');
    $post = new CommunityPost;
    $post->forceFill(['author_id' => $author->id, 'group_id' => null, 'tag' => null, 'body' => 'Lunch at noon',
        'created_at' => CarbonImmutable::now()->subMinutes(5)])->save();

    $source = <<<'PHP'
require 'tests/bootstrap.php';
$app = require 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing') || \Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'ems_testing') {
    throw new \RuntimeException('Disposable test database required.');
}
\Illuminate\Support\Facades\DB::connection()->beforeExecuting(static function (string $sql): void {
    if (str_contains($sql, '`community_posts`') && str_contains(strtolower($sql), 'for update')) {
        echo "ready\n";
        flush();
    }
});
try {
    app(\App\Modules\Social\Application\Commands\EditPost::class)->handle(
        \App\Support\Access::forUser(\App\Models\User::query()->findOrFail($argv[1])), $argv[2], 'Lunch at two', null,
    );
    echo json_encode(['edited' => true], JSON_THROW_ON_ERROR)."\n";
} catch (\RuntimeException $exception) {
    echo json_encode(['edited' => false, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR)."\n";
}
PHP;
    $environment = [
        'APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'),
        'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'ems_testing', 'DB_USERNAME' => 'ems_testing',
        'DB_HOST' => '127.0.0.1', 'DB_PORT' => (string) DB::connection()->getConfig('port'),
        'DB_PASSWORD' => (string) DB::connection()->getConfig('password'), 'DB_URL' => '', 'DB_SOCKET' => '',
        'EMS_MARIADB_TESTS' => 'disposable', 'BROADCAST_CONNECTION' => 'null', 'MAIL_MAILER' => 'array',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'null', 'QUEUE_CONNECTION' => 'sync',
    ];
    $worker = new Process([PHP_BINARY, '-r', $source, (string) $author->id, (string) $post->id], base_path(), $environment, timeout: 20);

    DB::beginTransaction();
    try {
        // The first save holds the row while the second has already read the
        // post and is about to lock it.
        CommunityPost::query()->whereKey($post->id)->lockForUpdate()->first();
        $worker->start();
        $deadline = microtime(true) + 10;
        while (! str_contains($worker->getOutput(), "ready\n") && $worker->isRunning() && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect($worker->getOutput())->toContain("ready\n")
            ->and($worker->isRunning())->toBeTrue();

        app(EditPost::class)->handle(Access::forUser($author), (string) $post->id, 'Lunch at one', null);
        DB::commit();

        $worker->wait();
        expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
        $lines = explode("\n", trim($worker->getOutput()));
        expect(json_decode((string) end($lines), true, 512, JSON_THROW_ON_ERROR))->toBe([
            'edited' => false,
            'message' => 'This post changed while you were editing it. Please try again.',
        ]);

        $fresh = CommunityPost::query()->find((string) $post->id);
        expect($fresh?->body)->toBe('Lunch at one')
            ->and(array_column($fresh->earlier_versions ?? [], 'body'))->toBe(['Lunch at noon']);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if ($worker->isRunning()) {
            $worker->stop(1);
        }
    }
});

it('never writes an edit onto a chat message whose removal another connection committed first', function () {
    $me = socraceUser('Kim Reyes', 'CHAT');
    $others = [socraceUser('Andrea Lim', 'CHAT'), socraceUser('Jose Rivera', 'CHAT')];
    $conversation = app(StartConversation::class)->handle(Access::forUser($me), array_map(static fn (User $u): int => (int) $u->id, $others), 'Depot');
    $message = app(SendMessage::class)->handle(Access::forUser($me), $conversation, 'Oops');

    // A second session on the same database removes the message and commits
    // after the edit has read it and passed its own removed check: just as
    // the edit looks up the conversation.
    config()->set('database.connections.socrace', config('database.connections.'.config('database.default')));
    $removed = false;
    DB::listen(static function (QueryExecuted $query) use (&$removed, $message): void {
        if (! $removed && $query->connectionName !== 'socrace' && str_contains($query->sql, 'chat_conversations')) {
            $removed = true;
            DB::connection('socrace')->table('chat_messages')->where('id', $message)
                ->update(['body' => null, 'removed_at' => CarbonImmutable::now()->utc()->format('Y-m-d H:i:s.v')]);
        }
    });

    try {
        expect(fn () => app(EditMessage::class)->handle(Access::forUser($me), $message, 'Never mind'))
            ->toThrow(RuntimeException::class, 'That message was removed.');
    } finally {
        DB::purge('socrace');
    }

    $gone = ChatMessage::query()->find($message);
    expect($removed)->toBeTrue()
        ->and($gone?->removed_at)->not->toBeNull()
        ->and($gone?->body)->toBeNull()
        ->and($gone?->edited_at)->toBeNull();
});
