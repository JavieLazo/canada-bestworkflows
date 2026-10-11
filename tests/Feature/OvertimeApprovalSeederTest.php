<?php

declare(strict_types=1);

use App\Domain\Attendance\CalendarDays;
use App\Models\EmployeeProfile;
use App\Models\OvertimeApproval;
use App\Models\User;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Timekeeping\Application\Queries\DayCorrections;
use App\Modules\Timekeeping\Domain\Attendance\OvertimeApprovalPolicy;
use App\Support\Access;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\OvertimeApprovalSeeder;
use Illuminate\Support\Facades\Storage;

/**
 * The demo data decides every day of overtime its punches produce — approved
 * in full, by a demo approver who is never the employee — so the demo payroll
 * includes examples of paid overtime. A re-run adds nothing and leaves a
 * walkthrough's own decision alone.
 */
function otseedFreeze(): void
{
    // A Thursday evening, after every seeded punch-out of the day.
    test()->travelTo(new DateTimeImmutable('2026-08-20T20:00:00+08:00'));
}

/** @return list<array<string, mixed>> the table's raw rows, by id */
function otseedRows(): array
{
    return array_values(OvertimeApproval::query()->orderBy('id')->get()->map(static fn (OvertimeApproval $row): array => $row->getAttributes())->all());
}

// DatabaseSeeder ends with SocialSeeder, which writes its demo photos and
// files to the `local` disk. Fake it, or every run leaves them in the real
// storage/app/private, where the next release zip would pick them up.
beforeEach(function (): void {
    Storage::fake('local');
});

it('approves every seeded day of overtime in full, never by the employee themself', function () {
    otseedFreeze();
    test()->seed(DatabaseSeeder::class);

    $admin = User::query()->where('email', 'admin@bestworkflows.local')->sole();
    $supervisor = User::query()->where('email', 'supervisor@bestworkflows.local')->sole();
    $reader = Access::forActor($admin);
    $rows = OvertimeApproval::query()->get()->keyBy(static fn (OvertimeApproval $row): string => $row->employee_id.'|'.$row->date->format('Y-m-d'));

    expect($rows)->not->toBeEmpty();

    $checked = 0;
    foreach (EmployeeProfile::query()->pluck('id') as $employeeId) {
        foreach (CalendarDays::each('2026-08-07', '2026-08-20') as $date) {
            $minutes = app(DayCorrections::class)->computedDay($reader, (string) $employeeId, $date)->overtimeMinutes ?? 0.0;
            $row = $rows[$employeeId.'|'.$date] ?? null;
            if (! OvertimeApprovalPolicy::decidable($minutes)) {
                expect($row)->toBeNull();

                continue;
            }

            $checked++;
            expect($row)->not->toBeNull();
            assert($row instanceof OvertimeApproval);
            expect($row->decision)->toBe('APPROVED')
                ->and($row->computed_minutes)->toBe(OvertimeApprovalPolicy::wholeMinutes($minutes))
                ->and($row->approved_minutes)->toBe(OvertimeApprovalPolicy::ceiling($minutes, 8.0))
                ->and($row->reviewed_by)->toBe($row->employee_id === (string) $admin->employee_id ? $supervisor->id : $admin->id);
        }
    }
    expect($checked)->toBe($rows->count());

    // Demo approvals leave no overtime issue in the payroll preview.
    test()->actingAs($admin);
    app()->forgetInstance(Access::class);
    $ids = array_values(EmployeeProfile::query()->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all());
    $result = PayrollData::calculateDrafts($ids, '2026-08-01', '2026-08-15');
    expect(collect($result['issues'])->pluck('message')->filter(fn (string $m) => str_contains($m, 'Daily Time Record'))->all())->toBe([]);
});

it('adds nothing on a re-run and keeps a decision made during a walkthrough', function () {
    otseedFreeze();
    test()->seed(DatabaseSeeder::class);

    $walked = OvertimeApproval::query()->orderBy('id')->firstOrFail();
    $walked->forceFill(['decision' => 'REJECTED', 'approved_minutes' => 0])->save();
    $before = otseedRows();

    test()->seed(OvertimeApprovalSeeder::class);

    expect(otseedRows())->toBe($before);
});
