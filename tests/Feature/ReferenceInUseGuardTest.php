<?php

declare(strict_types=1);

use App\Livewire\Records\ReferenceListPage;
use App\Models\AuditLog;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\EmployeeProfile;
use App\Models\EmploymentStatusOption;
use App\Models\LeaveCredit;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Platform\Records\ReferenceCatalogs;
use App\Support\Access;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * A reference entry that records still carry cannot be deleted, and the
 * count that says so is the OWNER'S, UNSCOPED (ReferenceCatalog::inUse):
 * an Employment status employees hold (any case, any surrounding spaces),
 * a Document type documents carry, a Leave type behind credits. The
 * list's "in use by N" label reads the same number, so a Records manager
 * who sees few or no employees never reads a held entry as free to delete.
 * Renaming a Document type is copied onto its documents, like a status.
 */

/** @param list<string> $modules */
function riugActor(array $modules = ['RECORDS'], bool $allData = true): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Riug '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    test()->actingAs(User::factory()->create(['role_id' => $role->getKey(), 'employee_id' => null]));
    app()->forgetInstance(Access::class);

    return app(Access::class);
}

function riugEmployee(string $status): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'RIUG-'.Str::random(6), 'first_name' => 'Rae', 'last_name' => 'Holder '.Str::random(3),
        'employment_status' => $status, 'date_hired' => '2024-01-01', 'is_active' => true,
    ])->save();

    return $employee;
}

it('refuses to delete an employment status any record carries, counted without the actor\'s scope and whatever the case', function () {
    $actor = riugActor();
    $status = new EmploymentStatusOption;
    $status->forceFill(['code' => '7', 'name' => 'Contractual'])->save();
    riugEmployee('Contractual');
    riugEmployee(' CONTRACTUAL ');
    $catalog = app(ReferenceCatalogs::class)->for('employment');

    // A Records manager with no employee grant still sees both holders.
    expect($catalog->inUseCounts($actor, [$catalog->find($actor, $status->id)]))->toBe([$status->id => 2]);
    expect(fn () => $catalog->delete($actor, $status->id))
        ->toThrow(RuntimeException::class, 'Employment status "Contractual" is still in use by 2 records. Move them to another entry first.');
    expect(EmploymentStatusOption::query()->whereKey($status->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'REFERENCE_DELETED')->count())->toBe(0);

    // The page's menu reads the same count, and the action itself refuses.
    $page = Livewire::test(ReferenceListPage::class, ['slug' => 'employment']);
    expect($page->html())->toContain('Delete · in use by 2');
    $page->call('confirmDelete', $status->id)->call('deleteConfirmed');
    expect(EmploymentStatusOption::query()->whereKey($status->id)->exists())->toBeTrue();

    // Renamed, both holders follow (the copy ignores case and spaces), and once nobody holds it the delete goes through.
    $catalog->save($actor, $status->id, 'Job Order', '');
    expect(EmployeeProfile::query()->where('employment_status', 'Job Order')->count())->toBe(2);
    EmployeeProfile::query()->update(['employment_status' => 'Regular']);
    expect($catalog->delete($actor, $status->id))->toBe('Job Order deleted.');
});

it('refuses to delete a document type documents carry and copies its rename onto them', function () {
    $actor = riugActor();
    $type = new DocumentType;
    $type->forceFill(['code' => '3', 'name' => 'Medical Certificate'])->save();
    $employee = riugEmployee('Regular');
    EmployeeDocument::query()->create([
        'employee_id' => $employee->id, 'name' => 'Fit to work', 'category' => 'Medical Certificate',
        'file_name' => 'fit.pdf', 'mime' => 'application/pdf', 'size' => 2048, 'path' => 'employee-documents/'.Str::ulid().'.pdf',
    ]);
    $catalog = app(ReferenceCatalogs::class)->for('documents');

    expect(fn () => $catalog->delete($actor, $type->id))->toThrow(RuntimeException::class, 'Document type "Medical Certificate" is still in use by 1 record.');
    $catalog->save($actor, $type->id, 'Medical Clearance', '');
    expect(EmployeeDocument::query()->value('category'))->toBe('Medical Clearance')
        ->and(AuditLog::query()->where('action', 'REFERENCE_UPDATED')->value('details'))->toContain('copied onto 1 employee record');
    EmployeeDocument::query()->delete();
    expect($catalog->delete($actor, $type->id))->toBe('Medical Clearance deleted.');
});

it('refuses to delete a leave type with credits, entries or requests behind its token', function () {
    $actor = riugActor();
    $type = new LeaveType;
    $type->forceFill(['code' => '4', 'name' => 'Bereavement Leave'])->save();
    $employee = riugEmployee('Regular');
    $credit = new LeaveCredit;
    $credit->forceFill(['employee_id' => $employee->id, 'year' => 2026, 'request_type' => 'BEREAVEMENT_LEAVE', 'credits' => 3])->save();
    $catalog = app(ReferenceCatalogs::class)->for('leave-types');

    expect($catalog->inUseCounts($actor, [$catalog->find($actor, $type->id)]))->toBe([$type->id => 1]);
    expect(fn () => $catalog->delete($actor, $type->id))->toThrow(RuntimeException::class, 'Leave type "Bereavement Leave" is still in use by 1 record.');
    $credit->delete();
    expect($catalog->delete($actor, $type->id))->toBe('Bereavement Leave deleted.');
});
