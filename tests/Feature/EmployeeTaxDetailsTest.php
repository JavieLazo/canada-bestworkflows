<?php

declare(strict_types=1);

use App\Domain\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\PayrollEmployeeTaxDetail;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Payroll\Application\Commands\SaveEmployeeTaxDetails;
use App\Modules\Payroll\Application\EmployeeTaxDetails;
use App\Modules\Payroll\Domain\Canada\TaxDetails;
use App\Support\Access;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\PayrollEvidenceFixture;

/**
 * An employee's tax details: one row per employee, changed in place by an
 * all-data PAYROLL holder alone, with every real change audited on its own
 * transaction. An employee with no row is at the starting position — the
 * basic claim amounts, no extra tax, CPP and EI deducted — and a blank claim
 * amount is stored as "the basic amount", never as a figure.
 */
function etdActor(bool $allData = true): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Tax details '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    $grant = new RoleModuleGrant;
    $grant->forceFill(['role_id' => $role->getKey(), 'module' => 'PAYROLL'])->save();
    test()->actingAs(User::factory()->create(['role_id' => $role->getKey()]));
    app()->forgetInstance(Access::class);

    return app(Access::class);
}

function etdAudits(): int
{
    return AuditLog::query()->where('action', AuditEvent::TAX_DETAILS_SAVED->value)->count();
}

/* |---| Reading |---| */

it('reads an employee with no saved row at the starting position', function () {
    ['employee' => $employee] = PayrollEvidenceFixture::make();

    $details = EmployeeTaxDetails::forEmployee($employee->id);

    expect($details->isStarting())->toBeTrue()
        ->and($details->province)->toBe('AB')
        ->and($details->federalClaim)->toBeNull()
        ->and($details->provincialClaim)->toBeNull()
        ->and($details->additionalTax)->toBe(0.0)
        ->and($details->deductPension)->toBeTrue()
        ->and($details->deductInsurance)->toBeTrue();
});

it('reads captured figures leniently, a missing key as the starting value', function () {
    $details = TaxDetails::fromArray(['federal_claim' => '18000', 'deduct_insurance' => false]);

    expect($details->province)->toBe('AB')
        ->and($details->federalClaim)->toBe(18000.0)
        ->and($details->provincialClaim)->toBeNull()
        ->and($details->deductPension)->toBeTrue()
        ->and($details->deductInsurance)->toBeFalse()
        ->and(TaxDetails::fromArray($details->toArray())->toArray())->toBe($details->toArray());
});

/* |---| Saving |---| */

it('saves the details in place, audits the change and writes nothing for a save that changes nothing', function () {
    ['actor' => $actor, 'employee' => $employee] = PayrollEvidenceFixture::make();
    $save = app(SaveEmployeeTaxDetails::class);

    $message = $save->handle($actor, $employee->id, 'ab', '$18,500.00', '', '25', true, false);

    $row = PayrollEmployeeTaxDetail::query()->where('employee_id', $employee->id)->sole();
    expect($message)->toContain('Tax details saved for')
        ->and($row->province)->toBe('AB')
        ->and($row->federal_claim)->toBe(18500.0)
        ->and($row->provincial_claim)->toBeNull()
        ->and($row->additional_tax)->toBe(25.0)
        ->and($row->deduct_pension)->toBeTrue()
        ->and($row->deduct_insurance)->toBeFalse()
        ->and($row->updated_by)->toBe((int) $actor->userId)
        ->and(etdAudits())->toBe(1);
    $audit = AuditLog::query()->where('action', AuditEvent::TAX_DETAILS_SAVED->value)->sole();
    expect($audit->details)->toContain('employed in Alberta')->toContain('federal claim $18,500.00')->toContain('Alberta claim basic amount')
        ->toContain('extra tax $25.00 each pay')->toContain('EI not deducted')
        ->and($audit->entity_id)->toBe($employee->id);

    // The same details again: no second row, no second audit line.
    expect($save->handle($actor, $employee->id, 'AB', '18500', null, '25.00', true, false))->toBeNull()
        ->and(PayrollEmployeeTaxDetail::query()->count())->toBe(1)
        ->and(etdAudits())->toBe(1);

    // A change lands on the same row.
    $save->handle($actor, $employee->id, 'AB', '', '', '', true, true);
    expect(PayrollEmployeeTaxDetail::query()->count())->toBe(1)
        ->and(EmployeeTaxDetails::forEmployee($employee->id)->isStarting())->toBeTrue()
        ->and(etdAudits())->toBe(2);
});

it('writes nothing when the starting position is saved for an employee with no row', function () {
    ['actor' => $actor, 'employee' => $employee] = PayrollEvidenceFixture::make();

    expect(app(SaveEmployeeTaxDetails::class)->handle($actor, $employee->id, 'AB', '', '', '', true, true))->toBeNull()
        ->and(PayrollEmployeeTaxDetail::query()->count())->toBe(0)
        ->and(etdAudits())->toBe(0);
});

/* |---| Refusals |---| */

it('refuses an actor without all-data PAYROLL', function () {
    ['employee' => $employee] = PayrollEvidenceFixture::make();
    $viewer = etdActor(allData: false);

    expect(fn () => app(SaveEmployeeTaxDetails::class)->handle($viewer, $employee->id, 'AB', '20000', '', '', true, true))
        ->toThrow(RuntimeException::class, 'cannot manage employee pay settings')
        ->and(PayrollEmployeeTaxDetail::query()->count())->toBe(0)
        ->and(etdAudits())->toBe(0);
});

it('refuses a province the system has no tax tables for, and an unknown one', function () {
    ['actor' => $actor, 'employee' => $employee] = PayrollEvidenceFixture::make();
    $save = app(SaveEmployeeTaxDetails::class);

    expect(fn () => $save->handle($actor, $employee->id, 'ON', '', '', '', true, true))->toThrow(RuntimeException::class, 'Ontario is not set up yet')
        ->and(fn () => $save->handle($actor, $employee->id, 'ZZ', '', '', '', true, true))->toThrow(RuntimeException::class, 'Choose the province of employment')
        ->and(PayrollEmployeeTaxDetail::query()->count())->toBe(0)
        ->and(etdAudits())->toBe(0);
});

it('refuses an amount that is not a figure at or above zero', function (mixed $federal, mixed $provincial, mixed $extra, string $names) {
    ['actor' => $actor, 'employee' => $employee] = PayrollEvidenceFixture::make();

    expect(fn () => app(SaveEmployeeTaxDetails::class)->handle($actor, $employee->id, 'AB', $federal, $provincial, $extra, true, true))
        ->toThrow(RuntimeException::class, $names)
        ->and(PayrollEmployeeTaxDetail::query()->count())->toBe(0)
        ->and(etdAudits())->toBe(0);
})->with([
    'a federal claim in words' => ['a lot', '', '', 'Federal claim amount'],
    'a provincial claim below zero' => ['', '-1', '', 'Provincial claim amount'],
    'extra tax in words' => ['', '', 'ten', 'Extra tax each pay'],
    'a claim beyond the column' => ['99999999999', '', '', 'Federal claim amount'],
]);

it('refuses an employee who does not exist', function () {
    ['actor' => $actor] = PayrollEvidenceFixture::make();

    expect(fn () => app(SaveEmployeeTaxDetails::class)->handle($actor, (string) Str::ulid(), 'AB', '20000', '', '', true, true))
        ->toThrow(RuntimeException::class, 'no longer exists')
        ->and(PayrollEmployeeTaxDetail::query()->count())->toBe(0);
});

/* |---| The migration |---| */

it('creates the table again without error and drops it on the way down', function () {
    $migration = require base_path('database/migrations/2026_11_24_0001_create_payroll_employee_tax_details_table.php');

    $migration->up();
    $migration->up();
    expect(Schema::hasTable('payroll_employee_tax_details'))->toBeTrue()
        ->and(Schema::hasIndex('payroll_employee_tax_details', 'petd_employee_unq'))->toBeTrue();

    $migration->down();
    expect(Schema::hasTable('payroll_employee_tax_details'))->toBeFalse();
    $migration->down();
    $migration->up();
    expect(Schema::hasTable('payroll_employee_tax_details'))->toBeTrue();
});
