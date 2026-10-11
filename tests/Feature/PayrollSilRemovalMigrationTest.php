<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\PayrollFinalizationSnapshot;
use App\Models\PayrollFormula;
use App\Models\PayrollRun;
use Database\Seeders\PayrollFormulaSeeder;
use Illuminate\Support\Facades\DB;

/** SIL cleanup removes only current configuration while preserving formula identities and frozen history. */
function silRemovalMigration(): mixed
{
    return require base_path('database/migrations/2026_10_10_0001_remove_sil_from_payroll_formulas_table.php');
}

function silRemovalLegacyFormula(): PayrollFormula
{
    $formula = new PayrollFormula;
    $formula->forceFill([
        'id' => 'fml-sil', 'code' => '2', 'key' => 'SIL', 'name' => 'Service Incentive Leave (SIL)',
        'expression' => 'Legacy SIL expression',
        'params' => [['key' => 'max_leave', 'label' => 'Max No. of Leave', 'value' => 9]],
    ])->save();

    return $formula;
}

it('seeds only active payroll formulas with stable generated identities and established codes', function () {
    $this->seed(PayrollFormulaSeeder::class);
    $this->seed(PayrollFormulaSeeder::class);

    expect(PayrollFormula::query()->orderBy('code')->get(['id', 'code', 'key'])->toArray())->toBe([
        ['id' => seededId(PayrollFormula::class, 'fml-ot'), 'code' => '1', 'key' => 'OT'],
        ['id' => seededId(PayrollFormula::class, 'fml-nsd'), 'code' => '3', 'key' => 'NSD'],
        ['id' => seededId(PayrollFormula::class, 'fml-rest_day'), 'code' => '4', 'key' => 'REST_DAY'],
    ]);
});

it('keeps seeded formula codes stable while a legacy SIL row still exists', function () {
    silRemovalLegacyFormula();
    $this->seed(PayrollFormulaSeeder::class);
    $this->seed(PayrollFormulaSeeder::class);

    expect(PayrollFormula::query()->orderBy('code')->pluck('key', 'code')->all())->toBe([
        1 => 'OT', 2 => 'SIL', 3 => 'NSD', 4 => 'REST_DAY',
    ]);

    silRemovalMigration()->up();
    $this->seed(PayrollFormulaSeeder::class);

    expect(PayrollFormula::query()->orderBy('code')->pluck('key', 'code')->all())->toBe([
        1 => 'OT', 3 => 'NSD', 4 => 'REST_DAY',
    ]);
});

it('removes only SIL while preserving custom active settings and historical evidence on every retry', function () {
    $this->seed(PayrollFormulaSeeder::class);
    $sil = silRemovalLegacyFormula();
    $ot = PayrollFormula::query()->where('key', 'OT')->sole();
    $ot->params = [['key' => 'percentage', 'label' => 'Percentage', 'value' => 1.75]];
    $ot->save();
    PayrollFormula::create([
        'code' => '5', 'key' => 'SIL_CUSTOM', 'name' => 'SIL reference',
        'expression' => 'Unrelated configuration', 'params' => [],
    ]);
    $remaining = PayrollFormula::query()->where('key', '!=', 'SIL')->orderBy('code')->get()->toArray();

    $run = PayrollRun::create([
        'code' => 'SIL-HISTORY', 'name' => 'Historical payroll with SIL evidence',
        'period_start' => '2026-08-01', 'period_end' => '2026-08-15', 'status' => 'FINALIZED',
    ]);
    $payload = json_encode(['inputs' => ['formulas' => [$sil->toArray()]]], JSON_THROW_ON_ERROR);
    $snapshot = new PayrollFinalizationSnapshot;
    $snapshot->forceFill([
        'run_id' => $run->id, 'schema_version' => 1, 'calculator_version' => 'historical-sil-test',
        'input_hash' => hash('sha256', $payload), 'payload_hash' => hash('sha256', $payload),
        'payload' => $payload, 'finalized_by' => 1,
    ])->save();
    $audit = new AuditLog;
    $audit->forceFill([
        'action' => 'FORMULA_UPDATED', 'entity' => 'PayrollFormula', 'entity_id' => $sil->id,
        'actor_id' => 1, 'actor_name' => 'Historical formula editor', 'details' => 'SIL max leave: 9',
    ])->save();
    $snapshotsBefore = DB::table('payroll_finalization_snapshots')->get()->map(fn (object $row): array => (array) $row)->all();
    $auditsBefore = DB::table('audit_logs')->get()->map(fn (object $row): array => (array) $row)->all();
    $runsBefore = PayrollRun::query()->get()->toArray();

    $migration = silRemovalMigration();
    foreach (['up', 'up', 'down', 'up'] as $method) {
        $migration->$method();

        expect(PayrollFormula::query()->orderBy('code')->get()->toArray())->toBe($remaining)
            ->and(DB::table('payroll_finalization_snapshots')->get()->map(fn (object $row): array => (array) $row)->all())->toBe($snapshotsBefore)
            ->and(DB::table('audit_logs')->get()->map(fn (object $row): array => (array) $row)->all())->toBe($auditsBefore)
            ->and(PayrollRun::query()->get()->toArray())->toBe($runsBefore);
    }
});
