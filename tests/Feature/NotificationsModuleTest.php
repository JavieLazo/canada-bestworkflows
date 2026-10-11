<?php

declare(strict_types=1);

use App\Domain\Notifications\Notifier;
use App\Livewire\Notifications\Concerns\OwnsInbox;
use App\Livewire\Notifications\Index;
use App\Livewire\Projects\ProjectsData;
use App\Livewire\Shell\NotificationBell;
use App\Models\EmployeeProfile;
use App\Models\Notification;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The Notifications module: /notifications gates on the SESSION alone ("an
 * inbox is not a module — every signed-in account has one",
 * notifications/page.tsx:15-21); every read and action is scoped to the
 * session account's own rows; and the Notifier fan-out addresses recipients
 * server-side with the reference's dedupe_key semantics.
 */

/**
 * Helpers are notif-prefixed: Pest loads every test file into one process.
 *
 * @param  list<string>  $modules  ModuleCode values the role is granted
 */
function notifRole(array $modules, bool $allData = false): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'Notif '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();

    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    return $role;
}

/**
 * @param  list<string>  $modules  ModuleCode values the user's role is granted
 */
function notifUser(array $modules = ['PORTAL'], bool $allData = false, ?string $employeeId = null, bool $isActive = true): User
{
    return User::factory()->create([
        'role_id' => notifRole($modules, $allData)->getKey(),
        'employee_id' => $employeeId,
        'is_active' => $isActive,
    ]);
}

/**
 * @param  list<string>  $modules  ModuleCode values the actor's role is granted
 */
function notifActor(array $modules = ['PORTAL'], bool $allData = false, ?string $employeeId = null): User
{
    $user = notifUser($modules, $allData, $employeeId);

    test()->actingAs($user);

    return $user;
}

function notifEmployee(string $code = 'EMP-001'): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => $code,
        'first_name' => 'Maria',
        'last_name' => 'Reyes',
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => true,
    ])->save();

    return $employee;
}

/**
 * A notification written the way only the server may write one: `user_id` is
 * not mass-assignable, so forceFill stands in for the Notifier's write path.
 *
 * @param  array{kind?: string, title?: string, body?: string|null, href?: string|null, dedupe_key?: string|null, read_at?: CarbonImmutable|null, created_at?: CarbonImmutable}  $overrides  column values replacing the defaults below; `user_id` always comes from `$to`
 */
function notifRow(User $to, array $overrides = []): Notification
{
    $row = new Notification;
    $row->forceFill(array_merge([
        'user_id' => $to->getKey(),
        'kind' => 'TASK_ASSIGNED',
        'title' => 'You were assigned T-0001',
        'body' => 'Prepare the August payroll file.',
        'href' => '/my-tasks',
        'dedupe_key' => null,
        'read_at' => null,
        'created_at' => CarbonImmutable::parse('2026-08-30 01:00:00', 'UTC'),
    ], $overrides))->save();

    return $row;
}

/*
|--------------------------------------------------------------------------
| Route gating — the session is the gate, not a module grant
|--------------------------------------------------------------------------
*/

it('sends a guest to the login page', function () {
    $this->get('/notifications')->assertRedirect(route('login'));
});

it('renders for any signed-in account, whatever modules its role holds', function () {
    // PORTAL only, no all_data, no employee link — the least-privileged
    // account there is still owns an inbox (notifications/page.tsx:15-21).
    notifActor(['PORTAL']);

    $this->get('/notifications')
        ->assertOk()
        ->assertSee('Notifications')
        ->assertSee('Everything addressed to you, newest first.');
});

/*
|--------------------------------------------------------------------------
| Scoping — only the session account's own rows, ever
|--------------------------------------------------------------------------
*/

it('shows only rows addressed to the session account', function () {
    $other = notifUser();
    notifRow($other, ['title' => 'Somebody else was assigned T-0009']);

    notifActor();

    Livewire::test(Index::class)
        ->assertDontSee('Somebody else was assigned T-0009')
        ->assertSee('Nothing to catch up on.');
});

it('lets follow() touch nothing when handed another account\'s id', function () {
    $other = notifUser();
    $theirs = notifRow($other);

    notifActor();

    Livewire::test(Index::class)
        ->call('follow', $theirs->getKey())
        ->assertNoRedirect();

    expect($theirs->fresh()->read_at)->toBeNull();
});

it('marks all read for the session account only', function () {
    $other = notifUser();
    $theirs = notifRow($other);

    $me = notifActor();
    $mine = notifRow($me, ['title' => 'Leave approved']);

    Livewire::test(Index::class)->call('markAllRead');

    expect($mine->fresh()->read_at)->not->toBeNull()
        ->and($theirs->fresh()->read_at)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The feed — rows, follow flow, filters, search
|--------------------------------------------------------------------------
*/

it('lists a row with its kind label, Unread tag and the mark-all button count', function () {
    $me = notifActor();
    notifRow($me, ['kind' => 'PAYSLIP_POSTED', 'title' => 'Your payslip is ready']);

    Livewire::test(Index::class)
        ->assertSee('Your payslip is ready')
        ->assertSee('Payslip posted')   // notificationKindLabel(PAYSLIP_POSTED)
        ->assertSee('Unread')
        ->assertSee('Mark all read')
        ->assertSee('(1)');
});

it('follow() marks the row read and redirects to its href', function () {
    $me = notifActor();
    $row = notifRow($me, ['href' => '/my-tasks']);

    Livewire::test(Index::class)
        ->call('follow', $row->getKey())
        ->assertRedirect('/my-tasks');

    expect($row->fresh()->read_at)->not->toBeNull();
});

it('follow() on a row without an href marks it read and stays', function () {
    $me = notifActor();
    $row = notifRow($me, ['href' => null]);

    Livewire::test(Index::class)
        ->call('follow', $row->getKey())
        ->assertNoRedirect();

    expect($row->fresh()->read_at)->not->toBeNull();
});

it('filters by status and shows the filtered empty state', function () {
    $me = notifActor();
    notifRow($me, ['title' => 'Still unread']);

    Livewire::test(Index::class)
        ->set('status', 'READ')
        ->assertDontSee('Still unread')
        ->assertSee('No notification matches those filters.')
        ->set('status', 'UNREAD')
        ->assertSee('Still unread');
});

it('search matches the kind by its label — "payslip" finds the payslip notifications', function () {
    $me = notifActor();
    notifRow($me, ['kind' => 'PAYSLIP_POSTED', 'title' => 'August 1-15 pay is posted', 'body' => null]);
    notifRow($me, ['kind' => 'TASK_ASSIGNED', 'title' => 'You were assigned T-0002', 'body' => null]);

    Livewire::test(Index::class)
        ->set('search', 'payslip')
        ->assertSee('August 1-15 pay is posted')
        ->assertDontSee('You were assigned T-0002');
});

/*
|--------------------------------------------------------------------------
| Notifier — the fan-out other modules call
|--------------------------------------------------------------------------
*/

it('Notifier::account writes one event row, addressee server-assigned, dedupe_key NULL', function () {
    $to = notifUser();

    Notifier::account($to->getKey(), 'LEAVE_REVIEWED', 'Your leave was approved', 'LV-0001 · Sick Leave', '/portal');

    $row = Notification::query()->where('user_id', $to->getKey())->first();

    expect($row)->not->toBeNull()
        ->and($row->kind)->toBe('LEAVE_REVIEWED')
        ->and($row->title)->toBe('Your leave was approved')
        ->and($row->body)->toBe('LV-0001 · Sick Leave')
        ->and($row->href)->toBe('/portal')
        ->and($row->dedupe_key)->toBeNull()
        ->and($row->read_at)->toBeNull();
});

it('Notifier::account does nothing when there is nobody to tell', function () {
    Notifier::account(null, 'LEAVE_REVIEWED', 'Nobody home');

    expect(Notification::query()->count())->toBe(0);
});

it('Notifier::employee tells every ACTIVE login of that employee, and nobody else', function () {
    $employee = notifEmployee();
    $active = notifUser(employeeId: $employee->getKey());
    $secondActive = notifUser(employeeId: $employee->getKey());
    $inactive = notifUser(employeeId: $employee->getKey(), isActive: false);
    $unrelated = notifUser();

    Notifier::employee($employee->getKey(), 'TASK_ASSIGNED', 'You were assigned T-0003');

    expect(Notification::query()->where('user_id', $active->getKey())->count())->toBe(1)
        ->and(Notification::query()->where('user_id', $secondActive->getKey())->count())->toBe(1)
        ->and(Notification::query()->where('user_id', $inactive->getKey())->count())->toBe(0)
        ->and(Notification::query()->where('user_id', $unrelated->getKey())->count())->toBe(0);
});

it('Notifier::employees reaches every ACTIVE login of a crew member, not a disabled older one', function () {
    // ProjectsData sends no notice itself: Projects fans out through
    // Notifier::employees(), so the disabled-older-login case is pinned there.
    $employee = notifEmployee('EMP-003');
    // The disabled account is created FIRST, so it holds the lower id — the
    // one an ->orderBy('id')->first() pick would hand the task to.
    $disabled = notifUser(employeeId: $employee->getKey(), isActive: false);
    $active = notifUser(employeeId: $employee->getKey());
    $secondActive = notifUser(employeeId: $employee->getKey());

    Notifier::employees([$employee->getKey()], [], 'TASK_ASSIGNED', 'You were assigned T-0004');

    expect(Notification::query()->where('user_id', $active->getKey())->count())->toBe(1)
        ->and(Notification::query()->where('user_id', $secondActive->getKey())->count())->toBe(1)
        ->and(Notification::query()->where('user_id', $disabled->getKey())->count())->toBe(0);
});

it('Notifier::employee does nothing for a null employee or one with no login', function () {
    Notifier::employee(null, 'TASK_ASSIGNED', 'No employee');
    Notifier::employee(notifEmployee('EMP-002')->getKey(), 'TASK_ASSIGNED', 'No login');

    expect(Notification::query()->count())->toBe(0);
});

it('Notifier::moduleHolders reaches all-data holders of the module and excludes the actor', function () {
    $reviewer = notifUser(['LEAVES'], allData: true);
    $actor = notifUser(['LEAVES'], allData: true);           // did the work: not news to them
    $ownScope = notifUser(['LEAVES'], allData: false);       // holds the module, not the scope
    $otherModule = notifUser(['PAYROLL'], allData: true);    // wrong module

    Notifier::moduleHolders('LEAVES', $actor->getKey(), 'LEAVE_FILED', 'Maria Reyes filed LV-0002', null, '/leaves');

    expect(Notification::query()->where('user_id', $reviewer->getKey())->count())->toBe(1)
        ->and(Notification::query()->where('user_id', $actor->getKey())->count())->toBe(0)
        ->and(Notification::query()->where('user_id', $ownScope->getKey())->count())->toBe(0)
        ->and(Notification::query()->where('user_id', $otherModule->getKey())->count())->toBe(0);
});

it('Notifier::employees tells every ACTIVE login of every listed employee once, in one fan-out', function () {
    $ana = notifEmployee('EMP-101');
    $ben = notifEmployee('EMP-102');
    $anaPhone = notifUser(['PORTAL'], false, $ana->id);
    $anaLaptop = notifUser(['PORTAL'], false, $ana->id);
    $anaOld = notifUser(['PORTAL'], false, $ana->id, isActive: false);
    $benOnly = notifUser(['PORTAL'], false, $ben->id);
    $bystander = notifUser(['PORTAL']);

    // The crew lists Ana twice — a duplicate id must not mean a duplicate row.
    Notifier::employees([$ana->id, $ben->id, $ana->id], [], 'TASK_ASSIGNED', 'Pour the slab');

    expect(Notification::query()->where('user_id', $anaPhone->getKey())->count())->toBe(1)
        ->and(Notification::query()->where('user_id', $anaLaptop->getKey())->count())->toBe(1)
        ->and(Notification::query()->where('user_id', $anaOld->getKey())->count())->toBe(0)
        ->and(Notification::query()->where('user_id', $benOnly->getKey())->count())->toBe(1)
        ->and(Notification::query()->where('user_id', $bystander->getKey())->count())->toBe(0)
        ->and(Notification::query()->count())->toBe(3);
});

it('Notifier::employees honours the except-list — the actor and the assigner are never told', function () {
    // One person can qualify twice: as the assigner (a users id on the task)
    // and as an assignee (an employee id on the crew). The caller passes both
    // the actor's and the assigner's accounts here so neither hears about
    // their own action through the crew fan-out.
    $ana = notifEmployee('EMP-103');
    $ben = notifEmployee('EMP-104');
    $anaAccount = notifUser(['PORTAL'], false, $ana->id);
    $benAccount = notifUser(['PORTAL'], false, $ben->id);

    Notifier::employees([$ana->id, $ben->id], [$anaAccount->getKey(), null, ''], 'TASK_COMMENT', 'New comment');

    expect(Notification::query()->where('user_id', $anaAccount->getKey())->count())->toBe(0)
        ->and(Notification::query()->where('user_id', $benAccount->getKey())->count())->toBe(1)
        ->and(Notification::query()->count())->toBe(1);
});

it('Notifier::employees writes nothing for an empty crew or for ids that name nobody', function () {
    notifUser(['PORTAL']);

    Notifier::employees([], [], 'TASK_ASSIGNED', 'Nobody');
    Notifier::employees([null, ''], [], 'TASK_ASSIGNED', 'Nobody');
    Notifier::employees(['no-such-employee'], [], 'TASK_ASSIGNED', 'Nobody');

    expect(Notification::query()->count())->toBe(0);
});

it('Notifier::reminders dedupes on (user_id, dedupe_key) and leaves the rest of the batch alone', function () {
    $me = notifUser();

    $rows = [
        ['dedupeKey' => 'TASK_OVERDUE:t1:2026-08-28', 'kind' => 'TASK_OVERDUE', 'title' => 'T-0001 is overdue', 'body' => null, 'href' => '/my-tasks'],
        ['dedupeKey' => 'TASK_DUE_SOON:t2:2026-09-01', 'kind' => 'TASK_DUE_SOON', 'title' => 'T-0002 is due soon', 'body' => null, 'href' => '/my-tasks'],
    ];

    Notifier::reminders($me->getKey(), $rows);
    // The sweep re-runs on the next load and offers the same reminders again —
    // the unique index swallows the repeats without disturbing new rows.
    Notifier::reminders($me->getKey(), [
        ...$rows,
        ['dedupeKey' => 'LEAVE_PENDING:l1:2026-08-25', 'kind' => 'LEAVE_PENDING', 'title' => 'LV-0003 is waiting', 'body' => null, 'href' => '/leaves'],
    ]);

    expect(Notification::query()->where('user_id', $me->getKey())->count())->toBe(3);

    // A DIFFERENT account announcing the same trigger still gets its own row.
    $other = notifUser();
    Notifier::reminders($other->getKey(), [$rows[0]]);

    expect(Notification::query()->where('dedupe_key', 'TASK_OVERDUE:t1:2026-08-28')->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| The stamped poll — the bell rides in the shell, so every page pays its tick
|--------------------------------------------------------------------------
*/

/**
 * Every statement the callback issued, as SQL.
 *
 * @return Collection<int, string> the `query` string of each query-log entry
 */
function notifQueriesDuring(Closure $work): Collection
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $work();

        return collect(DB::getQueryLog())->pluck('query');
    } finally {
        DB::disableQueryLog();
    }
}

it('does not re-read the bell on a tick where nothing arrived', function () {
    $me = notifActor();
    notifRow($me, ['title' => 'You were assigned T-0001']);

    $bell = Livewire::test(NotificationBell::class)->assertSee('T-0001');

    $queries = notifQueriesDuring(fn () => $bell->call('pollForChanges'));

    // The preview window never ran; one aggregate answered the tick.
    expect($queries->filter(fn (string $q): bool => str_contains($q, 'limit 10')))->toBeEmpty()
        ->and($queries->filter(fn (string $q): bool => str_contains(strtolower($q), 'count(*)')))->not->toBeEmpty();
});

it('redraws the bell on the tick after a notification arrives', function () {
    $me = notifActor();

    $bell = Livewire::test(NotificationBell::class)->assertDontSee('T-0002');

    notifRow($me, ['title' => 'You were assigned T-0002']);

    $bell->call('pollForChanges')->assertSee('T-0002');
});

it('redraws the bell when a notification is read from somewhere else', function () {
    $me = notifActor();
    $row = notifRow($me);

    $bell = Livewire::test(NotificationBell::class);

    // Read on another device: the total does not move, so COUNT(read_at) is
    // the half of the stamp that has to notice.
    $row->forceFill(['read_at' => CarbonImmutable::now()])->save();

    $queries = notifQueriesDuring(fn () => $bell->call('pollForChanges'));

    expect($queries->filter(fn (string $q): bool => str_contains($q, 'limit 10')))->not->toBeEmpty();
});

it('leaves one account\'s bell alone when another account is notified', function () {
    notifActor();
    $someoneElse = notifUser();

    $bell = Livewire::test(NotificationBell::class);

    notifRow($someoneElse, ['title' => 'Not yours']);

    $queries = notifQueriesDuring(fn () => $bell->call('pollForChanges'));

    expect($queries->filter(fn (string $q): bool => str_contains($q, 'limit 10')))->toBeEmpty();
});

it('does not re-read the notifications page on a tick where nothing arrived', function () {
    $me = notifActor();
    notifRow($me, ['title' => 'You were assigned T-0001']);

    $page = Livewire::test(Index::class)->assertSee('T-0001');

    $queries = notifQueriesDuring(fn () => $page->call('pollForChanges'));

    expect($queries->filter(fn (string $q): bool => str_contains($q, 'limit 500')))->toBeEmpty();
});

it('redraws the notifications page on the tick after one arrives', function () {
    $me = notifActor();

    $page = Livewire::test(Index::class)->assertDontSee('T-0002');

    notifRow($me, ['title' => 'You were assigned T-0002']);

    $page->call('pollForChanges')->assertSee('T-0002');
});

it('shares one inbox body between the bell and the page', function () {
    // The bell and the page both take own(), follow() and changeStamp() from
    // OwnsInbox, so the two cannot drift apart; a second copy would show up
    // here as a class that declares the method itself instead of composing it.
    foreach ([NotificationBell::class, Index::class] as $class) {
        expect(class_uses($class))->toContain(OwnsInbox::class);

        foreach (['own', 'follow', 'changeStamp'] as $method) {
            expect((new ReflectionMethod($class, $method))->getFileName())
                ->toBe((new ReflectionClass(OwnsInbox::class))->getFileName(), "{$class}::{$method}() is a local copy, not the trait's");
        }
    }
});
