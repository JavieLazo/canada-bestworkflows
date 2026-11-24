<?php

declare(strict_types=1);

use App\Models\PayrollDeduction;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MariaDB 11.4 proves `payroll_deductions.calculation` (2026_09_30_0010): the
 * literal-list CHECK is real, a calculation sits on one row while typed rows
 * (NULL) stay unlimited, and a run killed after the column ALTER resumes
 * through the tagging, the CHECK and the unique index.
 */
/** The migration under test, as `require` hands it back. */
function dcschemaMigration(): mixed
{
    return require base_path('database/migrations/2026_09_30_0010_add_calculation_to_payroll_deductions_table.php');
}

function dcschemaCheck(string $name): int
{
    $row = DB::selectOne(
        "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll_deductions' AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
        [$name],
    );

    return (int) ($row->n ?? 0);
}

it('resumes after the column ALTER and restores the CHECK and the unique index', function () {
    $m = dcschemaMigration();

    try {
        $m->down();
        expect(Schema::hasColumn('payroll_deductions', 'calculation'))->toBeFalse()
            ->and(dcschemaCheck('pded_calculation_chk'))->toBe(0);

        // A cron killed right after the ADD COLUMN, with only the seeded SSS row present.
        Schema::table('payroll_deductions', function (Blueprint $t): void {
            $t->string('calculation', 20)->nullable();
        });
        DB::table('payroll_deductions')->delete();
        $sss = new PayrollDeduction;
        $sss->forceFill(['id' => 'ded-1', 'code' => '1', 'name' => 'SSS'])->save();

        $m->up();
        $m->up();

        // SSS tagged; the four missing computed rows put back once.
        expect(PayrollDeduction::query()->findOrFail('ded-1')->calculation)->toBe('SSS')
            ->and(PayrollDeduction::query()->count())->toBe(5)
            ->and(PayrollDeduction::query()->whereNotNull('calculation')->count())->toBe(5)
            ->and(Schema::hasIndex('payroll_deductions', 'pded_calculation_unq', 'unique'))->toBeTrue()
            ->and(dcschemaCheck('pded_calculation_chk'))->toBe(1);

        // A second interrupted position: only the CHECK is missing.
        DB::statement('ALTER TABLE `payroll_deductions` DROP CONSTRAINT `pded_calculation_chk`');
        $m->up();
        expect(dcschemaCheck('pded_calculation_chk'))->toBe(1);
    } finally {
        $m->up();
    }
});

it('pins the calculation list and keeps each calculation on one row while typed rows stay free', function () {
    // Late / Undertime is the one computed row the migrations leave in place.
    expect(PayrollDeduction::query()->where('calculation', 'LATE_UNDERTIME')->count())->toBe(1);

    expect(fn () => PayrollDeduction::create(['code' => '90', 'name' => 'Made up', 'calculation' => 'RENT']))
        ->toThrow(QueryException::class, 'pded_calculation_chk');
    expect(fn () => PayrollDeduction::create(['code' => '91', 'name' => 'Late twice', 'calculation' => 'LATE_UNDERTIME']))
        ->toThrow(QueryException::class, 'pded_calculation_unq');

    PayrollDeduction::create(['code' => '92', 'name' => 'Cash Advance']);
    PayrollDeduction::create(['code' => '93', 'name' => 'Uniform']);
    expect(PayrollDeduction::query()->whereNull('calculation')->count())->toBe(2)
        ->and(PayrollDeduction::query()->where('calculation', 'LATE_UNDERTIME')->count())->toBe(1);
});
