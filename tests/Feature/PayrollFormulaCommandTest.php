<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\PayrollRates;
use App\Models\AuditLog;
use App\Models\PayrollFormula;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Payroll\Application\Commands\SaveFormulas;
use App\Support\Access;
use Database\Seeders\PayrollFormulaSeeder;

/** Formula writes validate seeded engine inputs and require an authorized audit per row. */

/** @param list<string> $modules */
function formulaCommandActor(bool $allData = true, array $modules = [ModuleCode::RECORDS]): Access
{
    $role = Role::create(['name' => 'Formula editor', 'all_data' => $allData, 'is_system' => false]);
    foreach ($modules as $module) {
        RoleModuleGrant::create(['role_id' => $role->id, 'module' => $module]);
    }

    return Access::forActor(User::factory()->create(['role_id' => $role->id, 'employee_id' => null]));
}

/** @return array<string, float> */
function formulaCommandValues(string $key): array
{
    return array_column(PayrollFormula::query()->where('key', $key)->sole()->params, 'value', 'key');
}

it('saves every active seeded formula as engine multipliers with a separate audit', function () {
    $this->seed(PayrollFormulaSeeder::class);
    $actor = formulaCommandActor();
    $drafts = [
        'OT' => ['percentage' => '1.5', 'max_ot' => '4'],
        'NSD' => ['percentage' => '0.2', 'max_nsd' => '6'],
        'REST_DAY' => ['percentage' => '1.4', 'max_hours' => '7.5'],
    ];

    $result = app(SaveFormulas::class)->handle($actor, $drafts);
    $rates = PayrollRates::fromFormulas(array_values(PayrollFormula::query()->get()->all()));

    expect($result)->toBe(['drafts' => $drafts, 'error' => null])
        ->and($rates->otRate)->toBe(1.5)
        ->and($rates->maxOtHours)->toBe(4.0)
        ->and($rates->nsdRate)->toBe(0.2)
        ->and($rates->maxNsdHours)->toBe(6.0)
        ->and($rates->restDayRate)->toBe(1.4)
        ->and($rates->maxRestDayHours)->toBe(7.5)
        ->and(AuditLog::query()->where('action', 'FORMULA_UPDATED')->count())->toBe(3)
        ->and(AuditLog::query()->where('action', 'FORMULA_UPDATED')->pluck('actor_id')->all())->toBe([(int) $actor->userId, (int) $actor->userId, (int) $actor->userId]);

    expect(app(SaveFormulas::class)->handle($actor, $drafts)['error'])->toBeNull()
        ->and(AuditLog::query()->where('action', 'FORMULA_UPDATED')->count())->toBe(3);
});

it('preserves legitimate zero rates and caps for every active formula', function () {
    $this->seed(PayrollFormulaSeeder::class);
    $drafts = [
        'OT' => ['percentage' => '0', 'max_ot' => '0'],
        'NSD' => ['percentage' => '0', 'max_nsd' => '0'],
        'REST_DAY' => ['percentage' => '0', 'max_hours' => '0'],
    ];
    expect(app(SaveFormulas::class)->handle(formulaCommandActor(), $drafts)['error'])->toBeNull();
    foreach ($drafts as $key => $params) {
        foreach (array_keys($params) as $param) {
            expect((float) formulaCommandValues($key)[$param])->toBe(0.0);
        }
    }
});

it('rejects unreadable and unsafe active drafts without a write or audit', function (string $key, string $param, string $value) {
    $this->seed(PayrollFormulaSeeder::class);
    $before = PayrollFormula::query()->orderBy('code')->get()->toArray();

    $result = app(SaveFormulas::class)->handle(formulaCommandActor(), [$key => [$param => $value]]);

    expect($result['error'])->toContain($param)
        ->and($result['drafts'][$key][$param])->toBe($value)
        ->and(PayrollFormula::query()->orderBy('code')->get()->toArray())->toBe($before)
        ->and(AuditLog::query()->count())->toBe(0);
})->with([
    'empty rate' => ['OT', 'percentage', ''],
    'whitespace rate' => ['OT', 'percentage', '  '],
    'unreadable rate' => ['OT', 'percentage', 'invalid'],
    'overflowed rate' => ['OT', 'percentage', '1e309'],
    'negative rate' => ['OT', 'percentage', '-0.01'],
    'excessive rate' => ['NSD', 'percentage', '10.01'],
    'empty cap' => ['REST_DAY', 'max_hours', ''],
    'negative cap' => ['NSD', 'max_nsd', '-0.01'],
    'excessive cap' => ['REST_DAY', 'max_hours', '24.01'],
]);

it('does not turn an emptied existing zero into an unchanged valid draft', function () {
    $this->seed(PayrollFormulaSeeder::class);
    $actor = formulaCommandActor();
    app(SaveFormulas::class)->handle($actor, ['OT' => ['percentage' => '0']]);

    expect(app(SaveFormulas::class)->handle($actor, ['OT' => ['percentage' => '']])['error'])->toContain('percentage')
        ->and((float) formulaCommandValues('OT')['percentage'])->toBe(0.0)
        ->and(AuditLog::query()->where('action', 'FORMULA_UPDATED')->count())->toBe(1);
});

it('preserves bounded legacy working hours while saving active overtime values', function () {
    $this->seed(PayrollFormulaSeeder::class);
    $row = PayrollFormula::query()->where('key', 'OT')->sole();
    $row->params = [...$row->params, ['key' => 'working_hours', 'label' => 'Working hours', 'value' => 7.5]];
    $row->save();

    expect(app(SaveFormulas::class)->handle(formulaCommandActor(), ['OT' => ['percentage' => '1.5']])['error'])->toBeNull()
        ->and((float) formulaCommandValues('OT')['working_hours'])->toBe(7.5)
        ->and((float) formulaCommandValues('OT')['percentage'])->toBe(1.5);
});

it('refuses a direct edit of an unsupported legacy formula row', function () {
    $this->seed(PayrollFormulaSeeder::class);
    PayrollFormula::create([
        'key' => 'SIL', 'code' => '2', 'name' => 'Service Incentive Leave (SIL)', 'expression' => 'Display only',
        'params' => [['key' => 'max_leave', 'label' => 'Max No. of Leave', 'value' => 6]],
    ]);
    $before = formulaCommandValues('SIL');

    expect(app(SaveFormulas::class)->handle(formulaCommandActor(), ['SIL' => ['max_leave' => '7']])['error'])->toContain('not supported')
        ->and(formulaCommandValues('SIL'))->toBe($before)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('refuses direct formula writes without records management permission', function (bool $allData, array $modules) {
    $this->seed(PayrollFormulaSeeder::class);
    $actor = formulaCommandActor($allData, array_values($modules));
    $before = formulaCommandValues('OT');

    expect(fn () => app(SaveFormulas::class)->handle($actor, ['OT' => ['percentage' => '1.5']]))->toThrow(RuntimeException::class)
        ->and(formulaCommandValues('OT'))->toBe($before)
        ->and(AuditLog::query()->count())->toBe(0);
})->with([
    'records without all data' => [false, [ModuleCode::RECORDS]],
    'payroll without records' => [true, [ModuleCode::PAYROLL]],
    'all data without grant' => [true, []],
]);

it('refuses an anonymous direct formula write', function () {
    $this->seed(PayrollFormulaSeeder::class);
    $before = formulaCommandValues('OT');

    expect(fn () => app(SaveFormulas::class)->handle(Access::forGuest(), ['OT' => ['percentage' => '1.5']]))->toThrow(RuntimeException::class)
        ->and(formulaCommandValues('OT'))->toBe($before)
        ->and(AuditLog::query()->count())->toBe(0);
});
