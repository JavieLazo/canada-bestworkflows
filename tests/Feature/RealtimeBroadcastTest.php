<?php

declare(strict_types=1);

use App\Domain\Notifications\Notifier;
use App\Events\RealtimeChange;
use App\Livewire\Edtr\EdtrPage;
use App\Livewire\Edtr\LiveFeed;
use App\Livewire\Edtr\PunchStation;
use App\Livewire\Edtr\Support\Hris;
use App\Livewire\Notifications\Index as NotificationsIndex;
use App\Livewire\Portal\MyPunches;
use App\Livewire\Shell\NotificationBell;
use App\Models\AttendanceLog;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Models\WorkSite;
use App\Support\Realtime;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Realtime: who may subscribe to what, what actually leaves the process, and
 * the promise that a host without PUSHER_* keys behaves exactly as it did
 * before any of this existed.
 *
 * The channel-authorisation block is the important half. A broadcast carries
 * no payload (App\Events\RealtimeChange), so the worst a wrongly-open channel
 * leaks today is the TIMING of somebody else's punch — but these callbacks are
 * the fourth enforcement layer beside the route gate, the module grant and
 * ScopesToActor, and they are tested like it.
 */
function rtRole(array $modules, bool $allData): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'Rt '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();

    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    return $role;
}

function rtUser(array $modules = ['EDTR'], bool $allData = false, ?string $employeeId = null): User
{
    return User::factory()->create([
        'role_id' => rtRole($modules, $allData)->getKey(),
        'employee_id' => $employeeId,
    ]);
}

function rtEmployee(?WorkSite $site = null, string $first = 'Liza', string $last = 'Domingo'): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'RT-'.random_int(10000, 99999),
        'first_name' => $first,
        'last_name' => $last,
        'position' => 'Field Engineer',
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => true,
        'work_site_id' => $site?->getKey(),
    ])->save();

    return $employee;
}

/**
 * Point the app at Pusher with throwaway credentials.
 *
 * Private-channel authorisation is entirely local — the driver runs the
 * routes/channels.php callback and, on a yes, HMACs the socket id with the app
 * secret. Nothing reaches the network, so these tests exercise the real
 * PusherBroadcaster rather than the `null` one the suite otherwise runs with
 * (which authorises nothing and would pass every negative for the wrong
 * reason).
 */
beforeEach(function (): void {
    config()->set('broadcasting.default', 'pusher');
    config()->set('broadcasting.connections.pusher.key', 'test-key');
    config()->set('broadcasting.connections.pusher.secret', 'test-secret');
    config()->set('broadcasting.connections.pusher.app_id', 'test-app-id');
    config()->set('broadcasting.connections.pusher.options.cluster', 'ap1');
    config()->set('bestworkflows.realtime.enabled', true);

    // Broadcast::channel() forwards through BroadcastManager::__call to the
    // CURRENTLY RESOLVED driver, so the callbacks bootstrap/app.php registered
    // at boot went onto the `null` broadcaster this suite runs with. Loading
    // them again now puts them on the Pusher driver the lines above just
    // selected. Production never does this: it boots with one driver and
    // registers once.
    require base_path('routes/channels.php');
});

/**
 * Ask /broadcasting/auth for a subscription, exactly as the browser does.
 * The real route, the real web middleware, the real channel callback.
 */
function rtMayJoin(User $user, string $channel): bool
{
    $response = test()->actingAs($user)->postJson('/broadcasting/auth', [
        'channel_name' => 'private-'.$channel,
        'socket_id' => '1234.5678',
    ]);

    if ($response->status() === 200) {
        return true;
    }

    expect($response->status())->toBe(403);

    return false;
}

/*
|--------------------------------------------------------------------------
| Channel authorisation — the fourth enforcement layer
|--------------------------------------------------------------------------
*/

it('lets an organisation-wide attendance reader onto the all-punches channel', function () {
    $user = rtUser(['EDTR'], allData: true);

    expect(rtMayJoin($user, 'attendance'))->toBeTrue();
});

it('keeps a self-only viewer off the all-punches channel', function () {
    $employee = rtEmployee();
    $user = rtUser(['EDTR'], allData: false, employeeId: (string) $employee->getKey());

    expect(rtMayJoin($user, 'attendance'))->toBeFalse();
});

it('keeps a role without the attendance modules off the all-punches channel', function () {
    // all_data alone is not a licence: the widening gate is EDTR/PAYROLL.
    $user = rtUser(['PORTAL'], allData: true);

    expect(rtMayJoin($user, 'attendance'))->toBeFalse();
});

it('lets a self-only viewer onto their OWN employee channel and nobody else\'s', function () {
    $own = rtEmployee();
    $other = rtEmployee(first: 'Ramon', last: 'Bautista');
    $user = rtUser(['EDTR'], allData: false, employeeId: (string) $own->getKey());

    expect(rtMayJoin($user, 'attendance.employee.'.$own->getKey()))->toBeTrue()
        ->and(rtMayJoin($user, 'attendance.employee.'.$other->getKey()))->toBeFalse();
});

it('lets an organisation-wide reader onto any employee channel', function () {
    $employee = rtEmployee();
    $user = rtUser(['EDTR'], allData: true);

    expect(rtMayJoin($user, 'attendance.employee.'.$employee->getKey()))->toBeTrue();
});

it('refuses an employee-less account every attendance channel', function () {
    // The ROWS_NONE arm: the subscription equivalent of the empty collection
    // ScopesToActor hands the same account.
    $employee = rtEmployee();
    $user = rtUser(['EDTR'], allData: false, employeeId: null);

    expect(rtMayJoin($user, 'attendance'))->toBeFalse()
        ->and(rtMayJoin($user, 'attendance.employee.'.$employee->getKey()))->toBeFalse();
});

it('lets an account onto its own inbox channel only', function () {
    $me = rtUser();
    $someoneElse = rtUser();

    expect(rtMayJoin($me, 'user.'.$me->getKey()))->toBeTrue()
        ->and(rtMayJoin($me, 'user.'.$someoneElse->getKey()))->toBeFalse();
});

it('does not let all_data hear another account\'s bell', function () {
    // all_data widens which ROWS you may read, never whose inbox you may hear.
    $admin = rtUser(['EDTR', 'PORTAL'], allData: true);
    $someoneElse = rtUser();

    expect(rtMayJoin($admin, 'user.'.$someoneElse->getKey()))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| What actually leaves the process
|--------------------------------------------------------------------------
*/

function rtSite(): WorkSite
{
    return WorkSite::query()->create([
        'name' => 'Site '.Str::random(4),
        'latitude' => 14.5995,
        'longitude' => 120.9842,
        'radius_meters' => 150,
        'geofence_exempt' => true,
    ]);
}

/** The private channel names one dispatched event knocks on. */
function rtChannels(RealtimeChange $event): array
{
    return array_map(static fn ($channel): string => $channel->name, $event->broadcastOn());
}

it('announces a punch on the all-punches channel and the employee\'s own', function () {
    Event::fake([RealtimeChange::class]);
    $this->withoutDefer();

    $employee = rtEmployee(rtSite());
    $this->actingAs(rtUser(['EDTR'], allData: true));

    Livewire::test(PunchStation::class)
        ->call('employeePicked', 'punch-employee', (string) $employee->getKey())
        ->call('punch', 'TIME_IN');

    expect(AttendanceLog::query()->count())->toBe(1);

    Event::assertDispatched(RealtimeChange::class, function (RealtimeChange $event) use ($employee): bool {
        return rtChannels($event) === [
            'private-attendance',
            'private-attendance.employee.'.$employee->getKey(),
        ];
    });
});

it('announces a day correction, which is a delete and an insert', function () {
    Event::fake([RealtimeChange::class]);
    $this->withoutDefer();

    $employee = rtEmployee(rtSite());
    $this->actingAs(rtUser(['EDTR'], allData: true));

    $today = Hris::todayKey();

    Livewire::test(EdtrPage::class)
        ->set('tab', 'dtr')
        ->call('employeePicked', 'dtr-employee', (string) $employee->getKey())
        ->call('openCorrection', $today)
        ->set('correctionForm.time_in', '08:00')
        ->set('correctionForm.time_out', '17:00')
        ->call('saveCorrection')
        ->assertSet('correctionError', null);

    // A correction can land while the employee it is about has their portal
    // open, so it knocks on the same two channels a punch does.
    Event::assertDispatched(RealtimeChange::class, function (RealtimeChange $event) use ($employee): bool {
        return rtChannels($event) === [
            'private-attendance',
            'private-attendance.employee.'.$employee->getKey(),
        ];
    });
});

it('records the punch even when the broadcaster is unreachable', function () {
    // The headline safety claim, tested against a real failure rather than a
    // mocked one: point the driver at a closed port so the send genuinely
    // throws. The punch must stand and the request must not.
    config()->set('broadcasting.connections.pusher.options.host', '127.0.0.1');
    config()->set('broadcasting.connections.pusher.options.port', 1);
    config()->set('broadcasting.connections.pusher.options.scheme', 'http');
    config()->set('broadcasting.connections.pusher.options.useTLS', false);
    config()->set('broadcasting.connections.pusher.client_options.connect_timeout', 1);
    Broadcast::purge('pusher');

    $this->withoutDefer();

    $employee = rtEmployee(rtSite());
    $this->actingAs(rtUser(['EDTR'], allData: true));

    Livewire::test(PunchStation::class)
        ->call('employeePicked', 'punch-employee', (string) $employee->getKey())
        ->call('punch', 'TIME_IN');

    expect(AttendanceLog::query()->where('employee_id', $employee->getKey())->count())->toBe(1);
});

it('carries nothing but the knock', function () {
    // The payload is empty BY CONSTRUCTION — see the class docblock. A test,
    // because the day someone adds a row to it is the day the channels stop
    // being safe to make coarse.
    expect((new RealtimeChange(['attendance']))->broadcastWith())->toBe([])
        ->and((new RealtimeChange(['attendance']))->broadcastAs())->toBe('changed');
});

it('announces nothing at all when no broadcaster can reach a browser', function () {
    Event::fake([RealtimeChange::class]);
    $this->withoutDefer();
    config()->set('bestworkflows.realtime.enabled', false);

    Realtime::announce(['attendance']);

    Event::assertNotDispatched(RealtimeChange::class);
});

it('does not announce a write that rolled back', function () {
    Event::fake([RealtimeChange::class]);
    $this->withoutDefer();

    try {
        DB::transaction(static function (): void {
            Realtime::announce(['attendance']);

            throw new RuntimeException('the punch failed after all');
        });
    } catch (RuntimeException) {
        // expected
    }

    // A knock for a punch that never happened would send every open browser
    // looking for a row that is not there.
    Event::assertNotDispatched(RealtimeChange::class);
});

it('announces a committed write once the transaction closes, not before', function () {
    Event::fake([RealtimeChange::class]);
    $this->withoutDefer();

    DB::transaction(static function (): void {
        Realtime::announce(['attendance']);

        Event::assertNotDispatched(RealtimeChange::class);
    });

    Event::assertDispatched(RealtimeChange::class);
});

it('knocks once for a whole notification fan-out, not once per recipient', function () {
    Event::fake([RealtimeChange::class]);
    $this->withoutDefer();

    $reviewers = collect([rtUser(['LEAVES'], allData: true), rtUser(['LEAVES'], allData: true)]);
    $actor = rtUser(['LEAVES'], allData: true);

    Notifier::moduleHolders(
        'LEAVES',
        $actor->getKey(),
        'LEAVE_FILED',
        'LV-0001 is waiting',
    );

    // Two rows, ONE outbound call carrying both channels — a leave filed for
    // five reviewers must not be five HTTPS round trips.
    Event::assertDispatchedTimes(RealtimeChange::class, 1);
    Event::assertDispatched(RealtimeChange::class, function (RealtimeChange $event) use ($reviewers): bool {
        $channels = rtChannels($event);
        sort($channels);
        $expected = $reviewers->map(static fn (User $u): string => 'private-user.'.$u->getKey())->sort()->values()->all();

        return $channels === $expected;
    });
});

it('does not knock when a reminder sweep found nothing new to say', function () {
    Event::fake([RealtimeChange::class]);
    $this->withoutDefer();

    $me = rtUser();
    $row = [[
        'dedupeKey' => 'TASK_OVERDUE:t1:2026-09-01',
        'kind' => 'TASK_OVERDUE',
        'title' => 'T-0001 is overdue',
        'body' => null,
        'href' => '/my-tasks',
    ]];

    Notifier::reminders($me->getKey(), $row);
    Event::assertDispatchedTimes(RealtimeChange::class, 1);

    // The same sweep again: insertOrIgnore writes nothing, so no bell moves.
    Notifier::reminders($me->getKey(), $row);
    Event::assertDispatchedTimes(RealtimeChange::class, 1);
});

/*
|--------------------------------------------------------------------------
| What the browser is told to listen to
|--------------------------------------------------------------------------
*/

it('subscribes an organisation-wide feed to every punch', function () {
    $this->actingAs(rtUser(['EDTR'], allData: true));

    expect(Livewire::test(LiveFeed::class)->effects['listeners'] ?? [])
        ->toContain('echo-private:attendance,.changed');
});

it('subscribes a self-only feed to its own employee alone', function () {
    $employee = rtEmployee();
    $this->actingAs(rtUser(['EDTR'], allData: false, employeeId: (string) $employee->getKey()));

    $listeners = Livewire::test(LiveFeed::class)->effects['listeners'] ?? [];

    expect($listeners)->toContain('echo-private:attendance.employee.'.$employee->getKey().',.changed')
        ->and($listeners)->not->toContain('echo-private:attendance,.changed');
});

it('subscribes an employee-less account to no attendance channel at all', function () {
    $this->actingAs(rtUser(['EDTR'], allData: false, employeeId: null));

    $listeners = Livewire::test(LiveFeed::class)->effects['listeners'] ?? [];

    foreach ($listeners as $listener) {
        expect($listener)->not->toStartWith('echo-');
    }
});

it('subscribes the portal card and the bell to their own channels', function () {
    $employee = rtEmployee();
    $me = rtUser(['PORTAL', 'EDTR'], allData: false, employeeId: (string) $employee->getKey());
    $this->actingAs($me);

    expect(Livewire::test(MyPunches::class)->effects['listeners'] ?? [])
        ->toContain('echo-private:attendance.employee.'.$employee->getKey().',.changed')
        ->and(Livewire::test(NotificationBell::class)->effects['listeners'] ?? [])
        ->toContain('echo-private:user.'.$me->getKey().',.changed')
        ->and(Livewire::test(NotificationsIndex::class)->effects['listeners'] ?? [])
        ->toContain('echo-private:user.'.$me->getKey().',.changed');
});

it('registers no Echo listener anywhere when realtime is off', function () {
    config()->set('bestworkflows.realtime.enabled', false);
    $employee = rtEmployee();
    $this->actingAs(rtUser(['EDTR'], allData: true, employeeId: (string) $employee->getKey()));

    // Not merely inert — ABSENT. A listener with no window.Echo makes Livewire
    // warn into every console on every page.
    foreach ([LiveFeed::class, MyPunches::class, NotificationBell::class, NotificationsIndex::class] as $component) {
        foreach (Livewire::test($component)->effects['listeners'] ?? [] as $listener) {
            expect($listener)->not->toStartWith('echo-');
        }
    }
});

it('still redraws on the poll when realtime is off', function () {
    // The backstop promise: with no broadcaster, the app is exactly what it
    // was before any of this existed.
    config()->set('bestworkflows.realtime.enabled', false);
    $employee = rtEmployee();
    $this->actingAs(rtUser(['EDTR'], allData: true));

    $feed = Livewire::test(LiveFeed::class)->assertDontSee('Domingo');

    AttendanceLog::query()->create([
        'employee_id' => (string) $employee->getKey(),
        'site_id' => (string) rtSite()->getKey(),
        'log_type' => 'TIME_IN',
        'source' => 'WEB_GPS',
        'is_geofenced' => true,
        'is_anomaly' => false,
    ]);

    $feed->call('pollForChanges')->assertSee('Domingo');
});
