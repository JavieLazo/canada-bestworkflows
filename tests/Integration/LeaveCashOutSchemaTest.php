<?php

declare(strict_types=1);

use App\Models\EmployeeProfile;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * On the production engine, the never-lapse year end and the cash-outs
 * (2026_10_31_0007 and 2026_10_31_0008) hold: a CARRY_FORWARD ledger entry
 * may be negative while CHARGE, LAPSE and CASH_OUT must be, CASH_OUT is an
 * admitted kind, `leave_cash_outs` refuses an unknown status, zero days, a
 * negative amount and a PAID row without a payslip, its payslip key is
 * RESTRICT, and both migrations re-run without a single ALTER.
 *
 * sqlite never creates a CHECK, so only this suite sees the real ALTERs.
 */
function lcoschemaEmployee(): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'LCO-'.Str::upper(Str::random(5)), 'first_name' => 'Cash', 'last_name' => 'Out '.Str::random(4),
        'position' => 'Clerk', 'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true,
    ])->save();

    return $employee;
}

/** @return list<string> every CREATE, ALTER or DROP the callback sends */
function lcoschemaDdlDuring(callable $callback): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $callback();
    } finally {
        DB::disableQueryLog();
    }

    return array_values(array_filter(
        array_column(DB::getQueryLog(), 'query'),
        static fn (string $sql): bool => preg_match('/^\s*(create|alter|drop)\b/i', $sql) === 1,
    ));
}

function lcoschemaEntry(EmployeeProfile $employee, string $kind, string $days): void
{
    DB::table('leave_credit_entries')->insert([
        'id' => (string) Str::ulid(), 'employee_id' => (string) $employee->getKey(), 'year' => 2026, 'request_type' => 'VACATION_LEAVE',
        'kind' => $kind, 'days' => $days, 'note' => 'schema', 'source_year' => $kind === 'CARRY_FORWARD' ? 2025 : null,
        'created_at' => '2026-10-31 01:00:00.000',
    ]);
}

/** @param array<string, mixed> $overrides */
function lcoschemaCashOut(EmployeeProfile $employee, array $overrides = []): string
{
    $id = (string) Str::ulid();
    DB::table('leave_cash_outs')->insert(array_merge([
        'id' => $id, 'employee_id' => (string) $employee->getKey(), 'request_type' => 'VACATION_LEAVE', 'days' => '2.5',
        'status' => 'PENDING', 'requested_at' => '2026-10-31 01:00:00.000', 'created_at' => '2026-10-31 01:00:00.000',
    ], $overrides));

    return $id;
}

it('admits a negative carry-forward and a cash-out entry, and refuses the signs the ledger forbids', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $employee = lcoschemaEmployee();

    lcoschemaEntry($employee, 'CARRY_FORWARD', '-1.5');
    lcoschemaEntry($employee, 'CARRY_FORWARD', '3');
    lcoschemaEntry($employee, 'CASH_OUT', '-2.5');
    expect(DB::table('leave_credit_entries')->where('employee_id', $employee->getKey())->count())->toBe(3);

    expect(fn () => lcoschemaEntry($employee, 'CARRY_FORWARD', '0'))->toThrow(QueryException::class, 'lce_days_chk');
    expect(fn () => lcoschemaEntry($employee, 'CASH_OUT', '1'))->toThrow(QueryException::class, 'lce_days_chk');
    expect(fn () => lcoschemaEntry($employee, 'REFUND', '1'))->toThrow(QueryException::class, 'lce_kind_chk');

    // The suite's teardown rolls every migration back, and each one puts
    // the ledger's earlier CHECK back: a cash-out entry and a negative
    // carry-forward are rows those refuse. They go first.
    DB::table('leave_credit_entries')->where('employee_id', $employee->getKey())->delete();
});

it('refuses a cash-out outside its constraints and keeps a paid one with its payslip', function () {
    $employee = lcoschemaEmployee();

    $pending = lcoschemaCashOut($employee);
    expect(DB::table('leave_cash_outs')->where('id', $pending)->value('status'))->toBe('PENDING');
    expect(fn () => lcoschemaCashOut($employee, ['status' => 'MAYBE']))->toThrow(QueryException::class, 'lco_status_chk');
    expect(fn () => lcoschemaCashOut($employee, ['days' => '0']))->toThrow(QueryException::class, 'lco_days_chk');
    expect(fn () => lcoschemaCashOut($employee, ['amount' => '-1']))->toThrow(QueryException::class, 'lco_days_chk');
    // PAID exactly when a payslip is named: neither half alone.
    expect(fn () => lcoschemaCashOut($employee, ['status' => 'PAID']))->toThrow(QueryException::class, 'lco_paid_chk');
    expect(fn () => DB::table('leave_cash_outs')->where('id', $pending)->update(['status' => 'PAID']))->toThrow(QueryException::class, 'lco_paid_chk');
    expect(DB::table('leave_cash_outs')->where('employee_id', $employee->getKey())->count())->toBe(1);

    // The employee key cascades; the payslip key is RESTRICT (a paid cash-out is evidence of the payslip).
    expect(Schema::hasForeignKey('leave_cash_outs', ['employee_id']))->toBeTrue()
        ->and(Schema::hasForeignKey('leave_cash_outs', ['payslip_id']))->toBeTrue();
    $keys = collect(Schema::getForeignKeys('leave_cash_outs'))->keyBy(static fn (array $key): string => implode(',', $key['columns']));
    expect(strtolower((string) $keys['payslip_id']['on_delete']))->toBe('restrict')
        ->and(strtolower((string) $keys['employee_id']['on_delete']))->toBe('cascade');
});

it('re-runs both migrations on a migrated schema without a single ALTER', function () {
    $carry = require base_path('database/migrations/2026_10_31_0007_allow_negative_carry_forward_on_leave_credit_entries_table.php');
    $cashOut = require base_path('database/migrations/2026_10_31_0008_create_leave_cash_outs_table.php');

    expect(lcoschemaDdlDuring(static fn () => $carry->up()))->toBe([]);
    expect(lcoschemaDdlDuring(static fn () => $cashOut->up()))->toBe([]);

    // Killed before the kind CHECK was re-added: only that step runs again.
    DB::statement('ALTER TABLE `leave_credit_entries` DROP CONSTRAINT `lce_kind_chk`');
    $ddl = lcoschemaDdlDuring(static fn () => $cashOut->up());
    expect($ddl)->toHaveCount(1)->and($ddl[0])->toStartWith('ALTER TABLE `leave_credit_entries` ADD CONSTRAINT `lce_kind_chk` CHECK');
});
