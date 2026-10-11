<?php

declare(strict_types=1);

use App\Models\EmployeeProfile;
use App\Models\LeaveCredit;
use App\Models\LeaveCreditEntry;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LeaveCreditSeeder;
use Illuminate\Support\Facades\Storage;

/**
 * The demo data credits every one of the eight demo employees with exactly
 * two rows for the current Manila year — Vacation Leave 15 and Sick Leave 15,
 * keyed as the requests are (`VACATION_LEAVE`, `SICK_LEAVE`) — reached from
 * DatabaseSeeder. Running LeaveCreditSeeder again is idempotent: the rows
 * keep their minted ids, untracked edits are restored and untracked stray
 * types are removed. Allocations with ledger history (including a cleared
 * row and migrated baselines) and other years are preserved.
 */
function lcseedFreeze(): void
{
    // Mid-year, so the "current Manila year" every row is keyed on is 2026
    // whether the run happens before or after UTC midnight.
    test()->travelTo(new DateTimeImmutable('2026-08-20T12:00:00+08:00'));
}

function lcseedEmployee(string $code): string
{
    return (string) EmployeeProfile::query()->where('employee_code', $code)->value('id');
}

/** @return array<string, float> request_type => credits, the employee's rows for one year */
function lcseedYear(string $employeeId, int $year): array
{
    $credits = [];

    foreach (LeaveCredit::query()->where('employee_id', $employeeId)->where('year', $year)->orderBy('request_type')->get() as $row) {
        $credits[$row->request_type] = $row->credits;
    }

    return $credits;
}

/** @return list<array<string, mixed>> the table's raw rows, by id */
function lcseedRows(): array
{
    $rows = [];

    foreach (LeaveCredit::query()->orderBy('id')->get() as $row) {
        $rows[] = $row->getAttributes();
    }

    return $rows;
}

// DatabaseSeeder ends with SocialSeeder, which writes its demo photos and
// files to the `local` disk. Fake it, or every run leaves them in the real
// storage/app/private, where the next release zip would pick them up.
beforeEach(function (): void {
    Storage::fake('local');
});

it('credits every demo employee with 15 vacation and 15 sick days for the year, and a re-run restores exactly that', function () {
    lcseedFreeze();
    test()->seed(DatabaseSeeder::class);

    $codes = ['1', '2', '3', '4', '5', '6', '7', '8'];
    $demo = ['SICK_LEAVE' => 15.0, 'VACATION_LEAVE' => 15.0];

    expect(LeaveCredit::query()->count())->toBe(16)
        ->and(LeaveCredit::query()->where('year', '!=', 2026)->count())->toBe(0);
    foreach ($codes as $code) {
        expect(lcseedYear(lcseedEmployee($code), 2026))->toBe($demo);
    }
    // BaseModel minted every id: no literal, and each one a ULID.
    foreach (LeaveCredit::query()->pluck('id') as $id) {
        expect(strlen((string) $id))->toBe(26);
    }

    $rows = lcseedRows();
    $emp1 = lcseedEmployee('1');
    $emp2 = lcseedEmployee('2');

    // The walkthrough: a stray type for the seeded year, a number changed,
    // a seeded row removed, and a prior year entered by hand — each through
    // the model as an untracked fixture edit, not through the credit command.
    (new LeaveCredit)->forceFill([
        'employee_id' => $emp1,
        'year' => 2026,
        'request_type' => 'OLD_LEAVE',
        'credits' => 3.0,
    ])->save();
    LeaveCredit::query()->where('employee_id', $emp1)->where('year', 2026)->where('request_type', 'SICK_LEAVE')->update(['credits' => 7.5]);
    LeaveCredit::query()->where('employee_id', $emp2)->where('year', 2026)->where('request_type', 'VACATION_LEAVE')->delete();
    (new LeaveCredit)->forceFill([
        'employee_id' => $emp1,
        'year' => 2025,
        'request_type' => 'VACATION_LEAVE',
        'credits' => 10.0,
    ])->save();

    expect(LeaveCredit::query()->count())->toBe(17)
        ->and(lcseedYear($emp1, 2026))->toBe(['OLD_LEAVE' => 3.0, 'SICK_LEAVE' => 7.5, 'VACATION_LEAVE' => 15.0])
        ->and(lcseedYear($emp2, 2026))->toBe(['SICK_LEAVE' => 15.0]);

    test()->seed(LeaveCreditSeeder::class);

    // Two rows per demo employee for 2026 again, the stray type gone, the
    // number restored, the deleted row back — and the 2025 row untouched,
    // because the cleanup is scoped to the seeded year.
    expect(LeaveCredit::query()->where('year', 2026)->count())->toBe(16)
        ->and(LeaveCredit::query()->count())->toBe(17)
        ->and(LeaveCredit::query()->where('request_type', 'OLD_LEAVE')->exists())->toBeFalse()
        ->and(lcseedYear($emp1, 2025))->toBe(['VACATION_LEAVE' => 10.0]);
    foreach ($codes as $code) {
        expect(lcseedYear(lcseedEmployee($code), 2026))->toBe($demo);
    }

    // Every row that survived the walkthrough kept its id, so the fifteen
    // untouched rows re-read byte for byte; only emp-2's re-created vacation
    // row carries a new one.
    $after = lcseedRows();
    $recreated = (string) LeaveCredit::query()->where('employee_id', $emp2)->where('year', 2026)->where('request_type', 'VACATION_LEAVE')->value('id');
    $keep = static fn (array $row): bool => $row['year'] !== 2025 && $row['id'] !== $recreated
        && ! ($row['employee_id'] === $emp2 && $row['request_type'] === 'VACATION_LEAVE');
    expect(array_values(array_filter($after, $keep)))->toBe(array_values(array_filter($rows, $keep)))
        ->and(count(array_filter($after, $keep)))->toBe(15);
});

it('preserves recorded demo allocations and never recreates a cleared allocation with history', function () {
    lcseedFreeze();
    test()->seed(DatabaseSeeder::class);
    $employeeId = lcseedEmployee('1');
    LeaveCredit::query()->where('employee_id', $employeeId)->where('request_type', 'SICK_LEAVE')->update(['credits' => 7.5]);
    LeaveCredit::query()->where('employee_id', $employeeId)->where('request_type', 'VACATION_LEAVE')->delete();
    (new LeaveCredit)->forceFill(['employee_id' => $employeeId, 'year' => 2026, 'request_type' => 'OLD_LEAVE', 'credits' => 3.0])->save();
    foreach (['SICK_LEAVE' => 7.5, 'VACATION_LEAVE' => 0.0, 'OLD_LEAVE' => 3.0] as $type => $days) {
        (new LeaveCreditEntry)->forceFill([
            'employee_id' => $employeeId, 'year' => 2026, 'request_type' => $type,
            'kind' => 'BASELINE', 'days' => $days, 'note' => 'Migrated fixture allocation.',
        ])->save();
    }
    $before = LeaveCreditEntry::query()->orderBy('id')->get()->map(static fn (LeaveCreditEntry $row): array => $row->getAttributes())->all();
    test()->seed(LeaveCreditSeeder::class);
    test()->seed(LeaveCreditSeeder::class);
    expect(lcseedYear($employeeId, 2026))->toBe(['OLD_LEAVE' => 3.0, 'SICK_LEAVE' => 7.5])
        ->and(LeaveCreditEntry::query()->orderBy('id')->get()->map(static fn (LeaveCreditEntry $row): array => $row->getAttributes())->all())->toBe($before);
});
