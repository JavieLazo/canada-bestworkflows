<?php

declare(strict_types=1);

use App\Models\PayrollFormula;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('creates a retry-safe MariaDB archive and retains exact evidence after the source is removed', function () {
    $migration = require base_path('database/migrations/2026_09_16_0001_preserve_legacy_upgrade_values.php');
    try {
        $migration->down();
        $migration->up();
        $formula = PayrollFormula::create([
            'code' => 'FML-002', 'key' => 'SIL', 'name' => 'Synthetic legacy SIL',
            'expression' => 'Archived display expression', 'params' => [['key' => 'days', 'value' => 7.25]],
        ]);
        $params = DB::table('payroll_formulas')->where('id', $formula->id)->value('params');
        $migration->up();
        $snapshot = DB::table('legacy_upgrade_values')->orderBy('source_table')->orderBy('source_id')->orderBy('source_field')->get()->toArray();
        $formula->delete();
        $migration->up();

        expect(Schema::hasIndex('legacy_upgrade_values', 'primary'))->toBeTrue()
            ->and(DB::table('legacy_upgrade_values')->where('source_id', $formula->id)->where('source_field', 'params')->value('original_value'))->toBe($params)
            ->and(DB::table('legacy_upgrade_values')->orderBy('source_table')->orderBy('source_id')->orderBy('source_field')->get()->toArray())->toEqual($snapshot)
            ->and(fn () => $migration->down())->toThrow(RuntimeException::class, 'Legacy upgrade evidence exists');

        $table = DB::selectOne("SELECT ENGINE, ROW_FORMAT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'legacy_upgrade_values'");
        expect($table->ENGINE)->toBe('InnoDB')->and($table->ROW_FORMAT)->toBe('Dynamic');
    } finally {
        // DatabaseMigrations may roll back only the synthetic archive owned
        // by this guarded disposable integration test.
        DB::table('legacy_upgrade_values')->delete();
    }
});
