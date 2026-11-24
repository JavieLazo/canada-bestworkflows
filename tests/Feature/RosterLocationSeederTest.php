<?php

declare(strict_types=1);

use App\Models\AttendanceLog;
use App\Models\EmployeeProfile;
use App\Models\RosterLocation;
use App\Models\WorkSite;
use App\Support\LocalClock;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\EmployeeSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\RosterLocationSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * The demo data carries exactly one mixed week of weekday locations (emp-3:
 * Mon WFH, Tue site-1, Wed site-2, Thu WFH, Fri site-1) and one lone WFH
 * Friday (emp-7); everyone else has no row and so keeps the Assigned
 * Worksite. Running DatabaseSeeder again changes none of it, the generated ids
 * survive, a re-run removes what a walkthrough added or changed, and the
 * seeded punches agree with the roster: from home on a WFH weekday, at that
 * weekday's own site otherwise, and exactly as before where no row exists.
 */
function rlocseedFreeze(): void
{
    // A Thursday, so every weekday falls inside the two-week punch window
    // twice, and a run straddling midnight cannot move a pair onto another
    // weekday between two runs.
    test()->travelTo(new DateTimeImmutable('2026-08-20T12:00:00+08:00'));
}

function rlocseedEmployee(string $code): string
{
    return (string) EmployeeProfile::query()->where('employee_code', $code)->value('id');
}

/** @return array<int, array{kind: string, site_id: string|null}> weekday => the row's place */
function rlocseedWeek(string $employeeId): array
{
    $week = [];

    foreach (RosterLocation::query()->where('employee_id', $employeeId)->orderBy('weekday')->get() as $row) {
        $week[$row->weekday] = ['kind' => $row->kind, 'site_id' => $row->site_id];
    }

    return $week;
}

/** @return list<AttendanceLog> the employee's seeded punches on one Manila weekday (0 Sunday … 6 Saturday), oldest first */
function rlocseedPunchesOn(string $employeeId, int $weekday): array
{
    return array_values(AttendanceLog::query()
        ->where('employee_id', $employeeId)
        ->orderBy('logged_at')
        ->get()
        ->filter(static fn (AttendanceLog $log): bool => CarbonImmutable::parse(LocalClock::dayKey($log->logged_at))->dayOfWeek === $weekday)
        ->all());
}

/**
 * @param  class-string<Model>  $model
 * @return list<array<string, mixed>> the table's raw rows, by id
 */
function rlocseedRows(string $model): array
{
    $rows = [];

    foreach ($model::query()->orderBy('id')->get() as $row) {
        $rows[] = $row->getAttributes();
    }

    return $rows;
}

/*
|--------------------------------------------------------------------------
| The seeded week
|--------------------------------------------------------------------------
*/

// DatabaseSeeder ends with SocialSeeder, which writes its demo photos and
// files to the `local` disk. Fake it, or every run leaves them in the real
// storage/app/private, where the next release zip would pick them up.
beforeEach(function (): void {
    Storage::fake('local');
});

it('seeds one mixed week for emp-3 and a lone WFH Friday for emp-7 under stable generated ids', function () {
    rlocseedFreeze();
    test()->seed(DatabaseSeeder::class);

    expect(RosterLocation::query()->count())->toBe(6)
        ->and(rlocseedWeek(rlocseedEmployee('3')))->toBe([
            1 => ['kind' => 'WFH', 'site_id' => null],
            2 => ['kind' => 'ONSITE', 'site_id' => seededId(WorkSite::class, 'site-1')],
            3 => ['kind' => 'ONSITE', 'site_id' => seededId(WorkSite::class, 'site-2')],
            4 => ['kind' => 'WFH', 'site_id' => null],
            5 => ['kind' => 'ONSITE', 'site_id' => seededId(WorkSite::class, 'site-1')],
        ])
        ->and(rlocseedWeek(rlocseedEmployee('7')))->toBe([
            5 => ['kind' => 'WFH', 'site_id' => null],
        ])
        ->and(RosterLocation::query()->orderBy('id')->pluck('id')->all())
        ->toEqualCanonicalizing([seededId(RosterLocation::class, 'rloc-3-1'), seededId(RosterLocation::class, 'rloc-3-2'), seededId(RosterLocation::class, 'rloc-3-3'), seededId(RosterLocation::class, 'rloc-3-4'), seededId(RosterLocation::class, 'rloc-3-5'), seededId(RosterLocation::class, 'rloc-7-5')]);

    // Everyone else has no row: the Assigned Worksite on every day.
    foreach (['1', '2', '4', '5', '6', '8'] as $code) {
        expect(rlocseedWeek(rlocseedEmployee($code)))->toBe([]);
    }
});

/*
|--------------------------------------------------------------------------
| Re-running the seeders
|--------------------------------------------------------------------------
*/

it('changes none of the seeded locations or punches when DatabaseSeeder runs a second time', function () {
    rlocseedFreeze();
    test()->seed(DatabaseSeeder::class);

    $locations = rlocseedRows(RosterLocation::class);
    $punches = rlocseedRows(AttendanceLog::class);

    test()->seed(DatabaseSeeder::class);

    expect(rlocseedRows(RosterLocation::class))->toBe($locations)
        ->and(rlocseedRows(AttendanceLog::class))->toBe($punches)
        ->and($locations)->toHaveCount(6)
        ->and(RosterLocation::query()->orderBy('id')->pluck('id')->all())
        ->toEqualCanonicalizing([seededId(RosterLocation::class, 'rloc-3-1'), seededId(RosterLocation::class, 'rloc-3-2'), seededId(RosterLocation::class, 'rloc-3-3'), seededId(RosterLocation::class, 'rloc-3-4'), seededId(RosterLocation::class, 'rloc-3-5'), seededId(RosterLocation::class, 'rloc-7-5')]);
});

it('restores the demo week when it runs again after a walkthrough edited it', function () {
    rlocseedFreeze();
    test()->seed([RoleSeeder::class, EmployeeSeeder::class, RosterLocationSeeder::class]);

    $emp3 = rlocseedEmployee('3');
    $editedId = seededId(RosterLocation::class, 'rloc-3-2');
    $deletedId = seededId(RosterLocation::class, 'rloc-7-5');

    // The walkthrough: a Saturday added, Tuesday switched to home, and
    // emp-7's only row removed — each through the table, as the roster
    // dialog would leave it.
    (new RosterLocation)->forceFill([
        'id' => 'rloc-stray',
        'employee_id' => $emp3,
        'weekday' => 6,
        'kind' => 'WFH',
        'site_id' => null,
    ])->save();
    RosterLocation::query()->whereKey(seededId(RosterLocation::class, 'rloc-3-2'))->update(['kind' => 'WFH', 'site_id' => null]);
    RosterLocation::query()->whereKey(seededId(RosterLocation::class, 'rloc-7-5'))->delete();

    expect(RosterLocation::query()->count())->toBe(6)
        ->and(rlocseedWeek($emp3)[6] ?? null)->toBe(['kind' => 'WFH', 'site_id' => null]);

    test()->seed(RosterLocationSeeder::class);

    expect(RosterLocation::query()->count())->toBe(6)
        ->and(RosterLocation::query()->whereKey('rloc-stray')->exists())->toBeFalse()
        ->and(rlocseedWeek($emp3))->toBe([
            1 => ['kind' => 'WFH', 'site_id' => null],
            2 => ['kind' => 'ONSITE', 'site_id' => seededId(WorkSite::class, 'site-1')],
            3 => ['kind' => 'ONSITE', 'site_id' => seededId(WorkSite::class, 'site-2')],
            4 => ['kind' => 'WFH', 'site_id' => null],
            5 => ['kind' => 'ONSITE', 'site_id' => seededId(WorkSite::class, 'site-1')],
        ])
        // The edited row keeps its id; a deleted row gets a fresh identity.
        ->and(RosterLocation::query()->where('employee_id', $emp3)->where('weekday', 2)->value('id'))->toBe($editedId)
        ->and(RosterLocation::query()->where('employee_id', rlocseedEmployee('7'))->where('weekday', 5)->value('id'))->toBe(seededId(RosterLocation::class, 'rloc-7-5'))->not->toBe($deletedId);
});

/*
|--------------------------------------------------------------------------
| The punches follow the roster
|--------------------------------------------------------------------------
*/

it('punches from home on a WFH weekday and at the weekday\'s own site otherwise', function () {
    rlocseedFreeze();
    test()->seed(DatabaseSeeder::class);

    $emp3 = rlocseedEmployee('3');
    $site1 = WorkSite::query()->findOrFail(seededId(WorkSite::class, 'site-1'));
    $site2 = WorkSite::query()->findOrFail(seededId(WorkSite::class, 'site-2'));

    // Monday and Thursday: from home. Two of each inside the window, one
    // pair per day.
    foreach ([1, 4] as $weekday) {
        $punches = rlocseedPunchesOn($emp3, $weekday);

        expect($punches)->toHaveCount(4);

        foreach ($punches as $punch) {
            expect($punch->location_kind)->toBe('WFH')
                ->and($punch->site_id)->toBeNull()
                ->and($punch->distance_meters)->toBeNull()
                ->and($punch->accuracy_meters)->toBe(12.0)
                ->and($punch->is_geofenced)->toBeTrue()
                ->and($punch->source)->toBe('WEB_GPS')
                // A home a few kilometres away, not a site's pin.
                ->and(abs((float) $punch->latitude - $site1->latitude))->toBeGreaterThan(0.01)
                ->and(abs((float) $punch->latitude - $site2->latitude))->toBeGreaterThan(0.01);
        }
    }

    // Tuesday and Friday at Makati, Wednesday at Quezon City — the weekday's
    // own site, inside its fence, even though emp-3's usual site is site-1.
    foreach ([2 => $site1, 3 => $site2, 5 => $site1] as $weekday => $site) {
        $punches = rlocseedPunchesOn($emp3, $weekday);

        expect($punches)->toHaveCount(4);

        foreach ($punches as $punch) {
            expect($punch->location_kind)->toBe('ONSITE')
                ->and($punch->site_id)->toBe((string) $site->getKey())
                ->and($punch->distance_meters)->not->toBeNull()
                ->and($punch->accuracy_meters)->toBeNull()
                ->and((float) $punch->latitude)->toEqualWithDelta($site->latitude + 0.0002, 0.000001)
                ->and((float) $punch->longitude)->toEqualWithDelta($site->longitude + 0.0002, 0.000001);
        }
    }

    // emp-7: Friday from home, Tuesday at the usual site-1 as before.
    $emp7 = rlocseedEmployee('7');

    expect(collect(rlocseedPunchesOn($emp7, 5))->pluck('location_kind')->unique()->all())->toBe(['WFH'])
        ->and(collect(rlocseedPunchesOn($emp7, 2))->pluck('site_id')->unique()->all())->toBe([seededId(WorkSite::class, 'site-1')]);

    // An employee with no row punches exactly as before: emp-1 on a Monday,
    // biometric, at site-1, with a measured distance.
    $fallback = rlocseedPunchesOn(rlocseedEmployee('1'), 1);

    expect($fallback)->toHaveCount(4);

    foreach ($fallback as $punch) {
        expect($punch->location_kind)->toBe('ONSITE')
            ->and($punch->site_id)->toBe(seededId(WorkSite::class, 'site-1'))
            ->and($punch->source)->toBe('BIOMETRIC')
            ->and($punch->distance_meters)->not->toBeNull();
    }

    // Across the whole table: the twelve home punches are exactly emp-3's
    // Mondays and Thursdays plus emp-7's Fridays, and no ONSITE row lacks a
    // site.
    expect(AttendanceLog::query()->where('location_kind', 'WFH')->count())->toBe(12)
        ->and(AttendanceLog::query()->where('location_kind', 'WFH')->whereNotNull('site_id')->count())->toBe(0)
        ->and(AttendanceLog::query()->where('location_kind', 'ONSITE')->whereNull('site_id')->count())->toBe(0)
        ->and(AttendanceLog::query()->where('location_kind', 'WFH')->whereNotIn('employee_id', [$emp3, $emp7])->count())->toBe(0);
});
