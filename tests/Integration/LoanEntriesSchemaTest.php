<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The loan ledger table on MariaDB (2026_11_02_0013): an entry's kind,
 * amount sign, status and approval stamp are shaped, the line and
 * employee keys keep it, and the migration rolls back and runs twice; the
 * take-home figure migration (2026_11_02_0014) adds its key once and
 * removes it on rollback.
 */
function loanEntriesMigration(): mixed
{
    return require base_path('database/migrations/2026_11_02_0013_create_payroll_recurring_line_entries_table.php');
}

function takeHomeMigration(): mixed
{
    return require base_path('database/migrations/2026_11_02_0014_add_minimum_take_home_to_payroll_pay_rules_table.php');
}

/** @return array<string, mixed> */
function loanEntryRow(string $lineId, string $employeeId, string $kind, float $amount, string $status, ?string $approvedAt): array
{
    return ['id' => (string) Str::ulid(), 'line_id' => $lineId, 'employee_id' => $employeeId, 'kind' => $kind, 'amount' => $amount, 'entry_date' => '2026-11-10',
        'reason' => 'Synthetic', 'reference' => null, 'status' => $status, 'prepared_by' => null, 'prepared_by_name' => null,
        'approved_by' => null, 'approved_by_name' => null, 'approved_at' => $approvedAt,
        'created_at' => now('UTC')->format('Y-m-d H:i:s.v'), 'updated_at' => null];
}

it('creates the ledger once, pins its rules, keeps the line and employee, and rolls back', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $migration = loanEntriesMigration();
    $migration->up();
    $migration->up();
    $stamp = now('UTC')->format('Y-m-d H:i:s.v');
    $employeeId = (string) Str::ulid();
    DB::table('employee_profiles')->insert(['id' => $employeeId, 'employee_code' => 'LNE-INT', 'first_name' => 'Synthetic', 'last_name' => 'Loan', 'date_hired' => '2024-01-01',
        'monthly_rate' => 30000, 'is_active' => true, 'created_at' => $stamp, 'updated_at' => null]);
    $deductionId = (string) Str::ulid();
    DB::table('payroll_deductions')->insert(['id' => $deductionId, 'code' => 'LNE-INT', 'name' => 'Synthetic loan', 'created_at' => $stamp, 'updated_at' => null]);
    $lineId = (string) Str::ulid();
    DB::table('payroll_recurring_lines')->insert(['id' => $lineId, 'employee_id' => $employeeId, 'kind' => 'DEDUCTION', 'deduction_id' => $deductionId, 'incentive_type_id' => null,
        'amount' => 500, 'total' => 2000, 'frequency' => 'EVERY', 'starts_on' => '2026-09-01', 'ends_on' => null, 'note' => null,
        'created_by' => null, 'created_by_name' => null, 'created_at' => $stamp, 'updated_at' => null]);

    DB::table('payroll_recurring_line_entries')->insert(loanEntryRow($lineId, $employeeId, 'OUTSIDE_PAYMENT', 100.0, 'DRAFT', null));
    DB::table('payroll_recurring_line_entries')->insert(loanEntryRow($lineId, $employeeId, 'ADJUSTMENT', -50.0, 'APPROVED', $stamp));
    expect(fn () => DB::table('payroll_recurring_line_entries')->insert(loanEntryRow($lineId, $employeeId, 'OUTSIDE_PAYMENT', 0.0, 'DRAFT', null)))->toThrow(QueryException::class)
        ->and(fn () => DB::table('payroll_recurring_line_entries')->insert(loanEntryRow($lineId, $employeeId, 'OUTSIDE_PAYMENT', -1.0, 'DRAFT', null)))->toThrow(QueryException::class)
        ->and(fn () => DB::table('payroll_recurring_line_entries')->insert(loanEntryRow($lineId, $employeeId, 'ADJUSTMENT', 0.0, 'DRAFT', null)))->toThrow(QueryException::class)
        ->and(fn () => DB::table('payroll_recurring_line_entries')->insert(loanEntryRow($lineId, $employeeId, 'REFUND', 1.0, 'DRAFT', null)))->toThrow(QueryException::class)
        ->and(fn () => DB::table('payroll_recurring_line_entries')->insert(loanEntryRow($lineId, $employeeId, 'OUTSIDE_PAYMENT', 1.0, 'DRAFT', $stamp)))->toThrow(QueryException::class)
        ->and(fn () => DB::table('payroll_recurring_line_entries')->insert(loanEntryRow($lineId, $employeeId, 'OUTSIDE_PAYMENT', 1.0, 'APPROVED', null)))->toThrow(QueryException::class)
        ->and(fn () => DB::table('payroll_recurring_lines')->where('id', $lineId)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table('employee_profiles')->where('id', $employeeId)->delete())->toThrow(QueryException::class)
        ->and(DB::table('payroll_recurring_line_entries')->where('line_id', $lineId)->count())->toBe(2);

    $migration->down();
    expect(Schema::hasTable('payroll_recurring_line_entries'))->toBeFalse();
    $migration->up();
    $migration->up();
    expect(Schema::hasTable('payroll_recurring_line_entries'))->toBeTrue();
});

it('adds the take-home key to every stored version once and removes it on rollback', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $migration = takeHomeMigration();
    $migration->down();
    $row = DB::table('payroll_pay_rules')->whereNotNull('params')->first(['id', 'params']);
    expect($row)->not->toBeNull();
    expect(array_key_exists('take_home', json_decode((string) $row->params, true)))->toBeFalse();
    $migration->up();
    $migration->up();
    $params = json_decode((string) DB::table('payroll_pay_rules')->where('id', $row->id)->value('params'), true);
    expect($params['take_home'])->toBe(['minimum' => null]);
    DB::table('payroll_pay_rules')->where('id', $row->id)->update(['params' => json_encode([...$params, 'take_home' => ['minimum' => 5000]])]);
    $migration->up();
    expect(json_decode((string) DB::table('payroll_pay_rules')->where('id', $row->id)->value('params'), true)['take_home'])->toBe(['minimum' => 5000]);
    $migration->down();
    expect(array_key_exists('take_home', json_decode((string) DB::table('payroll_pay_rules')->where('id', $row->id)->value('params'), true)))->toBeFalse();
    $migration->up();
});
