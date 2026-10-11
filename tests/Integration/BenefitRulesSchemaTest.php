<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The benefits-catalog migration on MariaDB: it resumes from any step, its
 * CHECKs hold, the former flag becomes the category once, and the posted
 * copies and payslips gain their columns.
 */
function benefitRulesMigration(): mixed
{
    return require base_path('database/migrations/2026_10_12_0003_add_benefit_rules_to_payroll_incentive_types_table.php');
}

/** @return array<string, mixed> */
function benefitRulesRow(string $code, bool $taxable): array
{
    return [
        'id' => (string) Str::ulid(), 'code' => $code, 'name' => "Type {$code}", 'description' => null, 'is_taxable' => $taxable,
        'created_at' => now('UTC')->format('Y-m-d H:i:s.v'), 'updated_at' => null,
    ];
}

it('adds the rule columns once, backfills the category from the flag and resumes from a partial run', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $migration = benefitRulesMigration();

    // Back to the former shape, with one exempt and one taxable type on it.
    $migration->down();
    expect(Schema::hasColumn('payroll_incentive_types', 'tax'))->toBeFalse()
        ->and(Schema::hasColumn('payslip_incentives', 'tax'))->toBeFalse()
        ->and(Schema::hasColumn('payslips', 'ceiling_benefits'))->toBeFalse();
    DB::table('payroll_incentive_types')->insert([benefitRulesRow('901', false), benefitRulesRow('902', true)]);

    $migration->up();
    $migration->up();
    $rows = DB::table('payroll_incentive_types')->whereIn('code', ['901', '902'])->orderBy('code')->get(['code', 'tax', 'amount_type', 'frequency', 'proration', 'min_service_months', 'counts_toward_13th']);
    expect($rows->map(static fn (object $r): array => [$r->code, $r->tax, $r->amount_type, $r->frequency, $r->proration, (int) $r->min_service_months, (int) $r->counts_toward_13th])->all())
        ->toBe([['901', 'EXEMPT', 'TYPED', 'ONCE', 'NONE', 0, 0], ['902', 'TAXABLE', 'TYPED', 'ONCE', 'NONE', 0, 0]])
        ->and(Schema::hasColumn('payslip_incentives', 'counts_toward_13th'))->toBeTrue()
        ->and(Schema::hasColumn('payslips', 'ceiling_benefits'))->toBeTrue();

    $checks = DB::table('information_schema.TABLE_CONSTRAINTS')
        ->whereRaw('CONSTRAINT_SCHEMA = DATABASE()')
        ->where('TABLE_NAME', 'payroll_incentive_types')
        ->where('CONSTRAINT_TYPE', 'CHECK')
        ->where('CONSTRAINT_NAME', 'like', 'pit\_%')
        ->pluck('CONSTRAINT_NAME')->sort()->values()->all();
    expect($checks)->toBe(['pit_amount_type_chk', 'pit_frequency_chk', 'pit_proration_chk', 'pit_tax_chk']);

    // A run killed after the columns and before the CHECKs resumes; a
    // category HR set since is never overwritten by the re-run.
    DB::statement('ALTER TABLE `payroll_incentive_types` DROP CONSTRAINT `pit_tax_chk`');
    DB::table('payroll_incentive_types')->where('code', '902')->update(['tax' => 'NOT_INCOME', 'is_taxable' => false]);
    $migration->up();
    expect(DB::table('payroll_incentive_types')->where('code', '902')->value('tax'))->toBe('NOT_INCOME')
        ->and(DB::table('information_schema.TABLE_CONSTRAINTS')->whereRaw('CONSTRAINT_SCHEMA = DATABASE()')->where('TABLE_NAME', 'payroll_incentive_types')->where('CONSTRAINT_NAME', 'pit_tax_chk')->exists())->toBeTrue();
});

it('refuses an unknown amount rule, frequency, proration or category', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    foreach ([['amount_type' => 'WEEKLY'], ['frequency' => 'DAILY'], ['proration' => 'WEEKS'], ['tax' => 'FREE']] as $bad) {
        expect(fn () => DB::table('payroll_incentive_types')->insert(benefitRulesRow('9'.count($bad).Str::random(2), true) + $bad))->toThrow(QueryException::class);
    }
    DB::table('payroll_incentive_types')->insert(benefitRulesRow('977', true) + ['amount_type' => 'FIXED', 'amount' => 2000.0, 'frequency' => 'FIRST', 'tax' => 'NOT_INCOME']);
    expect(DB::table('payroll_incentive_types')->where('code', '977')->value('frequency'))->toBe('FIRST');
});
