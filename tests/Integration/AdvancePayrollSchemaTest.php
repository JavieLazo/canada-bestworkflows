<?php

declare(strict_types=1);

use App\Models\EmployeeProfile;
use App\Models\PayrollRun;
use App\Models\Payslip;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The advance payroll on MariaDB (2026_10_31_0001 and _0002): a payslip's
 * counted date sits inside its period and its presumed days are never
 * negative; a settlement names two posted payslips that cannot be deleted
 * from under it, settles a payslip once and spans dates in order; the
 * paid-day freeze reads COALESCE(attendance_through, period_end). The
 * migrations resume from any step.
 */

/** A posted payslip of a finalized regular run, for the ledger's keys. */
function advschemaPayslip(string $employeeId, string $start, string $end, ?string $through): Payslip
{
    $run = PayrollRun::create(['code' => 'ADV-'.Str::random(5), 'name' => 'Advance '.Str::random(5), 'status' => 'FINALIZED',
        'period_start' => $start, 'period_end' => $end, 'kind' => 'REGULAR', 'attendance_through' => $through]);
    $payslip = new Payslip;
    $payslip->forceFill(['run_id' => $run->id, 'employee_id' => $employeeId, 'period_start' => $start, 'period_end' => $end,
        'attendance_through' => $through, 'presumed_days' => $through === null ? 0 : 1, 'gross_pay' => 15000, 'total_deductions' => 0, 'net_pay' => 15000])->save();

    return $payslip;
}

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function advschemaSettlement(Payslip $settling, Payslip $settled, array $changes = []): array
{
    return [...[
        'id' => (string) Str::ulid(), 'payslip_id' => $settling->id, 'settled_payslip_id' => $settled->id, 'employee_id' => $settled->employee_id,
        'settled_code' => 'PR-1', 'from_date' => '2026-09-15', 'to_date' => '2026-09-15', 'presumed_amount' => 1363.64, 'actual_amount' => 0,
        'amount' => -1363.64, 'details' => json_encode(['presumed' => [], 'actual' => []]), 'created_at' => now('UTC')->format('Y-m-d H:i:s.v'),
    ], ...$changes];
}

it('holds the counted date inside the period, keeps the presumed count non-negative and settles a payslip once', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $employee = EmployeeProfile::create(['employee_code' => 'ADVS-1', 'first_name' => 'Advance', 'last_name' => 'Schema', 'date_hired' => '2024-01-01', 'employment_status' => 'Regular', 'is_active' => true]);

    $advance = advschemaPayslip($employee->id, '2026-09-01', '2026-09-15', '2026-09-14');
    $next = advschemaPayslip($employee->id, '2026-09-16', '2026-09-30', null);

    foreach ([
        'a counted date two days before the period' => ['attendance_through' => '2026-08-30'],
        'a counted date on the period end' => ['attendance_through' => '2026-09-15'],
        'negative presumed days' => ['presumed_days' => -1],
    ] as $case => $changes) {
        expect(fn () => DB::table('payslips')->where('id', $advance->id)->update($changes))->toThrow(QueryException::class, null, $case);
    }
    // The day before the period is allowed (2026_11_05_0001): a member whose first counted day was
    // unfinished is counted through it, every day presumed (PayrollAdvance::countedThrough).
    expect(DB::table('payslips')->where('id', $advance->id)->update(['attendance_through' => '2026-08-31']))->toBe(1);
    DB::table('payslips')->where('id', $advance->id)->update(['attendance_through' => '2026-09-14']);

    DB::table('payslip_settlements')->insert(advschemaSettlement($next, $advance));
    foreach ([
        'the same payslip settled again' => [],
        'dates out of order' => ['settled_payslip_id' => $next->id, 'from_date' => '2026-09-16', 'to_date' => '2026-09-15'],
        'an unknown settling payslip' => ['settled_payslip_id' => $next->id, 'payslip_id' => (string) Str::ulid()],
    ] as $case => $changes) {
        expect(fn () => DB::table('payslip_settlements')->insert(advschemaSettlement($next, $advance, $changes)))->toThrow(QueryException::class, null, $case);
    }
    // Neither payslip can be deleted from under the settlement.
    expect(fn () => DB::table('payslips')->where('id', $advance->id)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table('payslips')->where('id', $next->id)->delete())->toThrow(QueryException::class);

    // The freeze's expression, as the query spells it.
    $frozenThrough = DB::table('payslips')->where('id', $advance->id)->selectRaw('COALESCE(attendance_through, period_end) AS frozen_through')->value('frozen_through');
    expect(substr((string) $frozenThrough, 0, 10))->toBe('2026-09-14');
});

it('resumes each advance-payroll migration from a partial run', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $columns = require base_path('database/migrations/2026_10_31_0001_add_attendance_through_to_payroll_tables.php');
    $ledger = require base_path('database/migrations/2026_10_31_0002_create_payslip_settlements_table.php');

    $ledger->down();
    $columns->down();
    expect(Schema::hasColumn('payslips', 'settlement'))->toBeFalse()
        ->and(Schema::hasTable('payslip_settlements'))->toBeFalse();

    // A run stopped after the run column and the ledger's CREATE, before the indexes, keys and CHECKs.
    Schema::table('payroll_runs', static fn ($t) => $t->date('attendance_through')->nullable());
    $columns->up();
    $columns->up();
    Schema::create('payslip_settlements', static function ($t): void {
        $t->engine = 'InnoDB';
        $t->char('id', 26)->primary();
        $t->char('payslip_id', 26);
        $t->char('settled_payslip_id', 26);
        $t->char('employee_id', 26);
        $t->string('settled_code', 40);
        $t->date('from_date');
        $t->date('to_date');
        $t->decimal('presumed_amount', 12, 2);
        $t->decimal('actual_amount', 12, 2);
        $t->decimal('amount', 12, 2);
        $t->json('details');
        $t->dateTime('created_at', 3);
    });
    $ledger->up();
    $ledger->up();

    $checks = DB::table('information_schema.TABLE_CONSTRAINTS')
        ->whereRaw('CONSTRAINT_SCHEMA = DATABASE()')->where('CONSTRAINT_TYPE', 'CHECK')
        ->whereIn('CONSTRAINT_NAME', ['psl_presumed_chk', 'psl_through_chk', 'pss_span_chk'])->count();
    expect($checks)->toBe(3)
        ->and(Schema::hasIndex('payslip_settlements', 'pss_settled_unq'))->toBeTrue()
        ->and(Schema::hasForeignKey('payslip_settlements', ['payslip_id']))->toBeTrue()
        ->and(Schema::hasForeignKey('payslip_settlements', ['settled_payslip_id']))->toBeTrue()
        ->and(Schema::hasColumn('payslips', 'presumed_days'))->toBeTrue();

    // The original CHECK is back; the relaxation (2026_11_05_0001) is the next migration's.
    $relaxed = require base_path('database/migrations/2026_11_05_0001_relax_payslip_counted_date_check_table.php');
    $relaxed->up();
    $relaxed->up();
});

it('relaxes the counted-date CHECK to the day before the period, from any step, and puts it back', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $relaxed = require base_path('database/migrations/2026_11_05_0001_relax_payslip_counted_date_check_table.php');
    $employee = EmployeeProfile::create(['employee_code' => 'ADVS-2', 'first_name' => 'Relaxed', 'last_name' => 'Schema', 'date_hired' => '2024-01-01', 'employment_status' => 'Regular', 'is_active' => true]);
    $clause = static fn (): string => strtolower((string) DB::table('information_schema.CHECK_CONSTRAINTS')
        ->whereRaw('CONSTRAINT_SCHEMA = DATABASE()')->where('TABLE_NAME', 'payslips')->where('CONSTRAINT_NAME', 'psl_through_chk')->value('CHECK_CLAUSE'));

    // As migrated: the day before is allowed, two days before and the period end are not.
    expect($clause())->toContain('interval 1 day');
    $payslip = advschemaPayslip($employee->id, '2026-09-01', '2026-09-15', '2026-08-31');
    expect(fn () => DB::table('payslips')->where('id', $payslip->id)->update(['attendance_through' => '2026-08-30']))->toThrow(QueryException::class);
    DB::table('payslips')->where('id', $payslip->id)->update(['attendance_through' => '2026-09-14']);

    // down() restores the original bound; up() twice relaxes it again and changes nothing the second time.
    $relaxed->down();
    expect($clause())->not->toContain('interval 1 day')
        ->and(fn () => DB::table('payslips')->where('id', $payslip->id)->update(['attendance_through' => '2026-08-31']))->toThrow(QueryException::class);
    $relaxed->up();
    $relaxed->up();
    expect($clause())->toContain('interval 1 day')
        ->and(DB::table('payslips')->where('id', $payslip->id)->update(['attendance_through' => '2026-08-31']))->toBe(1);

    // A run stopped after the drop, before the add, resumes at the add.
    DB::statement('ALTER TABLE `payslips` DROP CONSTRAINT `psl_through_chk`');
    $relaxed->up();
    expect($clause())->toContain('interval 1 day');
    // Back inside the original bound, so the suite's rollback (down(), the strict CHECK) can run.
    DB::table('payslips')->where('id', $payslip->id)->update(['attendance_through' => '2026-09-14']);
});
