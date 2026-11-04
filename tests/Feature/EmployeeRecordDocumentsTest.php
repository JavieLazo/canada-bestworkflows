<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Employees\Documents;
use App\Livewire\Employees\History;
use App\Models\AuditLog;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\EmployeeEmploymentHistory;
use App\Models\EmployeeProfile;
use App\Models\EmployeeRecordChangeRequest;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Workforce\Application\Commands\AttachDocument;
use App\Modules\Workforce\Application\Commands\DeleteDocument;
use App\Modules\Workforce\Application\Commands\ReviewRecordChange;
use App\Modules\Workforce\Application\Commands\SubmitRecordDocument;
use App\Modules\Workforce\Application\Commands\WithdrawRecordChange;
use App\Modules\Workforce\Application\Queries\EmployeeDocuments;
use App\Modules\Workforce\Application\Queries\RecordChangeRequests;
use App\Modules\Workforce\Infrastructure\RecordChangeStorageUsage;
use App\Support\Access;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Official documents remain management-only while historical request evidence is preserved. */
function recordDocsEmployee(): EmployeeProfile
{
    return EmployeeProfile::query()->create(['employee_code' => 'DOC-'.Str::random(6), 'first_name' => 'Record',
        'last_name' => 'Owner', 'date_hired' => '2024-01-01', 'monthly_rate' => 20000]);
}

/** @param list<string> $modules */
function recordDocsUser(?EmployeeProfile $employee, array $modules = [ModuleCode::PROFILE], bool $all = false): User
{
    $role = Role::query()->create(['name' => 'Document role '.Str::random(8), 'all_data' => $all, 'is_system' => false]);
    foreach ($modules as $module) {
        RoleModuleGrant::query()->create(['role_id' => $role->id, 'module' => $module]);
    }

    return User::factory()->create(['role_id' => $role->id, 'employee_id' => $employee?->id]);
}

function recordDocsOfficial(EmployeeProfile $employee, User $manager): EmployeeDocument
{
    DocumentType::query()->firstOrCreate(['code' => 'DOC'], ['name' => 'Document']);
    app(AttachDocument::class)->handle(Access::forActor($manager), $employee->id, UploadedFile::fake()->create('record.pdf', 3, 'application/pdf'),
        ['name' => 'Official record', 'document_no' => 'DOC-123', 'category' => 'Document', 'expires_on' => '']);

    return EmployeeDocument::query()->where('employee_id', $employee->id)->sole();
}

function recordDocsStored(EmployeeProfile $employee, string $name, string $category): EmployeeDocument
{
    return EmployeeDocument::query()->create([
        'employee_id' => $employee->id, 'name' => $name, 'category' => $category,
        'file_name' => $name.'.pdf', 'mime' => 'application/pdf', 'size' => 2048,
        'path' => 'employee-documents/'.Str::ulid().'.pdf',
    ]);
}

function recordDocsHistoricalRequest(EmployeeProfile $employee, User $owner, EmployeeDocument $document): EmployeeRecordChangeRequest
{
    $request = new EmployeeRecordChangeRequest;
    $request->forceFill([
        'employee_id' => $employee->id, 'pending_employee_id' => $employee->id, 'employee_name' => 'Record Owner',
        'kind' => 'DOCUMENT', 'section' => 'documents', 'operation' => 'DELETE', 'row_id' => $document->id,
        'baseline' => ['document_no' => $document->document_no, 'name' => $document->name,
            'category' => $document->category, 'expires_on' => $document->expires_on?->format('Y-m-d'),
            'file_name' => $document->file_name, 'mime' => $document->mime, 'size' => $document->size],
        'proposed' => [],
        'form' => ['document_path' => $document->path, 'document_size' => $document->size,
            'document_file_name' => $document->file_name, 'document_mime' => $document->mime],
        'changes' => [['field' => 'document_name', 'label' => 'Document name', 'before' => $document->name,
            'after' => null, 'before_known' => true, 'after_known' => true]],
        'reason' => 'Historical document removal request.', 'status' => 'PENDING',
        'requested_by' => $owner->id, 'requested_by_name' => 'Record Owner',
    ])->save();

    return $request;
}

it('allows management to list download and manage official documents including their linked record', function (bool $own) {
    Storage::fake('local');
    $employee = recordDocsEmployee();
    $manager = recordDocsUser($own ? $employee : recordDocsEmployee(), [ModuleCode::EMPLOYEES], true);
    $document = recordDocsOfficial($employee, $manager);
    $actor = Access::forActor($manager);
    expect(app(EmployeeDocuments::class)->page($actor, $employee->id, 1, 20)->total())->toBe(1);
    $this->actingAs($manager)->get(route('employees.documents.download', $document->id))
        ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    Livewire::test(Documents::class, ['employeeId' => $employee->id])->assertSee('Official record')->assertSee('Add document');
    $this->get(route('employees.documents.export', ['employee' => $employee->id]))->assertOk();
    app(DeleteDocument::class)->handle($actor, $employee->id, $document->id);
    expect(EmployeeDocument::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing($document->path);
    expect(EmployeeRecordChangeRequest::query()->count())->toBe(0);
})->with([false, true]);

it('offers catalog and employee document categories once under their displayed labels', function () {
    $employee = recordDocsEmployee();
    $other = recordDocsEmployee();
    $manager = recordDocsUser(null, [ModuleCode::EMPLOYEES], true);
    DocumentType::query()->create(['code' => 'CONTRACT', 'name' => 'Contract']);
    DocumentType::query()->create(['code' => 'MEDICAL', 'name' => 'Medical']);
    recordDocsStored($employee, 'Legacy contract', 'CONTRACT');
    recordDocsStored($employee, 'Current contract', 'Contract');
    recordDocsStored($employee, 'Legacy identity', 'GOVERNMENT_ID');
    recordDocsStored($employee, 'Retired category record', 'Archived category');
    recordDocsStored($other, 'Foreign category record', 'Foreign category');
    $queries = app(EmployeeDocuments::class);
    $actor = Access::forActor($manager);

    expect($queries->filterCategories($actor, $employee->id))
        ->toBe(['Archived category', 'Contract', 'Government ID', 'Medical'])
        ->and($queries->categories($actor))->toBe(['Contract', 'Medical']);
});

it('filters the employee document query by displayed category before pagination', function () {
    $employee = recordDocsEmployee();
    $other = recordDocsEmployee();
    $manager = recordDocsUser(null, [ModuleCode::EMPLOYEES], true);
    $legacy = recordDocsStored($employee, 'Legacy contract', 'CONTRACT');
    $current = recordDocsStored($employee, 'Current contract', 'Contract');
    $medical = recordDocsStored($employee, 'Medical certificate', 'Medical');
    recordDocsStored($other, 'Foreign contract', 'Contract');
    $queries = app(EmployeeDocuments::class);
    $actor = Access::forActor($manager);
    $filtered = $queries->page($actor, $employee->id, 9, 1, 'Contract');

    expect($filtered->total())->toBe(2)->and($filtered->currentPage())->toBe(2)->and($filtered->count())->toBe(1)
        ->and($queries->page($actor, $employee->id, 1, 20, 'Contract')->pluck('id')->all())
        ->toEqualCanonicalizing([$legacy->id, $current->id])
        ->and($queries->page($actor, $employee->id, 1, 20, 'Medical')->pluck('id')->all())->toBe([$medical->id])
        ->and($queries->page($actor, $employee->id, 1, 20, 'Missing category')->total())->toBe(0)
        ->and($queries->page($actor, $employee->id, 1, 20)->total())->toBe(3);
});

it('keeps the selected document category through rendering and clamps its named paginator', function () {
    $employee = recordDocsEmployee();
    $manager = recordDocsUser(null, [ModuleCode::EMPLOYEES], true);
    for ($index = 1; $index <= 61; $index++) {
        recordDocsStored($employee, 'Document '.$index, $index <= 41 ? 'Contract' : 'Medical');
    }
    $this->actingAs($manager);

    $component = Livewire::test(Documents::class, ['employeeId' => $employee->id]);
    $component->call('setPage', 4, 'documentsPage')
        ->assertSet('paginators.documentsPage', 4)
        ->set('category', 'Contract')
        ->assertSet('paginators.documentsPage', 3)
        ->assertViewHas('list', fn (LengthAwarePaginator $rows): bool => $rows->total() === 41 && $rows->count() === 1);

    $component->assertSeeHtml('href="'.e(route('employees.documents.export', ['employee' => $employee->id, 'category' => 'Contract'])).'"');

    $component->call('$refresh')
        ->assertSet('category', 'Contract')
        ->assertSet('paginators.documentsPage', 3)
        ->set('perPage', 50)
        ->assertSet('paginators.documentsPage', 1)
        ->assertViewHas('list', fn (LengthAwarePaginator $rows): bool => $rows->total() === 41 && $rows->count() === 41);

    $component->set('category', 'Medical')
        ->assertViewHas('list', fn (LengthAwarePaginator $rows): bool => $rows->total() === 20);
    $component->set('category', '')
        ->assertViewHas('list', fn (LengthAwarePaginator $rows): bool => $rows->total() === 61 && $rows->count() === 50);
});

it('denies official document access without Employee Records management permission', function (bool $all, string $module): void {
    Storage::fake('local');
    $employee = recordDocsEmployee();
    $owner = recordDocsUser($employee, [$module], $all);
    $reviewer = recordDocsUser(null, [ModuleCode::EMPLOYEES], true);
    $document = recordDocsOfficial($employee, $reviewer);
    $actor = Access::forActor($owner);
    $auditCount = AuditLog::query()->count();
    expect(app(EmployeeDocuments::class)->page($actor, $employee->id, 1, 20)->total())->toBe(0);
    expect(app(EmployeeDocuments::class)->page($actor, $employee->id, 1, 20, 'Document')->total())->toBe(0);
    expect(app(EmployeeDocuments::class)->filterCategories($actor, $employee->id))->toBe([]);
    expect(fn () => app(EmployeeDocuments::class)->download($actor, $document->id))->toThrow(HttpException::class);
    $this->actingAs($owner);
    foreach ([route('employees.documents.download', $document->id), route('employees.documents.export', ['employee' => $employee->id])] as $url) {
        $response = $this->get($url)->assertHeaderMissing('Content-Disposition');
        if ($actor->hasModule(ModuleCode::EMPLOYEES)) {
            $response->assertForbidden();
        } else {
            $response->assertRedirect('/profile');
        }
    }
    Livewire::test(Documents::class, ['employeeId' => $employee->id])->assertForbidden();
    Livewire::test(Documents::class, ['employeeId' => $employee->id, 'own' => true])->assertForbidden();
    expect(fn () => app(SubmitRecordDocument::class)->attach($actor, UploadedFile::fake()->create('private.pdf', 2), [], 'Add a document'))
        ->toThrow(HttpException::class);
    expect(fn () => app(SubmitRecordDocument::class)->delete($actor, $document->id, 'Delete my document'))->toThrow(HttpException::class);
    expect(fn () => app(AttachDocument::class)->handle($actor, $employee->id, null, ['name' => '', 'document_no' => '', 'category' => '', 'expires_on' => '']))
        ->toThrow(HttpException::class);
    expect(fn () => app(DeleteDocument::class)->handle($actor, $employee->id, $document->id))->toThrow(HttpException::class);
    expect(EmployeeDocument::query()->count())->toBe(1)->and(EmployeeRecordChangeRequest::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe($auditCount);
    Storage::disk('local')->assertExists($document->path);
    expect(Storage::disk('local')->allFiles('record-change-documents'))->toBe([]);
})->with([
    'employee own scope' => [false, ModuleCode::EMPLOYEES],
    'profile all-data only' => [true, ModuleCode::PROFILE],
]);

it('refuses a documents action after management access is revoked', function (): void {
    Storage::fake('local');
    $employee = recordDocsEmployee();
    $manager = recordDocsUser(null, [ModuleCode::EMPLOYEES], true);
    $document = recordDocsOfficial($employee, $manager);
    $this->actingAs($manager);
    $page = Livewire::test(Documents::class, ['employeeId' => $employee->id]);
    $auditCount = AuditLog::query()->count();
    RoleModuleGrant::query()->where('role_id', $manager->role_id)->delete();
    $this->actingAs($manager->fresh());
    app()->forgetInstance(Access::class);
    $page->call('removeDocument', $document->id)->assertForbidden();
    expect($document->fresh())->not->toBeNull()
        ->and(AuditLog::query()->count())->toBe($auditCount);
    Storage::disk('local')->assertExists($document->path);
});

it('keeps historical document requests resolvable without permitting approval or own evidence access', function (bool $withdraw): void {
    Storage::fake('local');
    $employee = recordDocsEmployee();
    $owner = recordDocsUser($employee, [ModuleCode::PROFILE, ModuleCode::EMPLOYEES], true);
    $manager = recordDocsUser(null, [ModuleCode::EMPLOYEES], true);
    $document = recordDocsOfficial($employee, $manager);
    $request = recordDocsHistoricalRequest($employee, $owner, $document);
    $values = $request->getAttributes();
    $ownerAccess = Access::forActor($owner);
    $managerAccess = Access::forActor($manager);
    $queries = app(RecordChangeRequests::class);
    expect($queries->find($ownerAccess, $request->id, true)?->has_before_evidence)->toBeFalse()
        ->and($queries->find($managerAccess, $request->id)?->has_before_evidence)->toBeTrue()
        ->and($queries->evidence($ownerAccess, $request->id, 'before'))->toBeNull();
    $this->actingAs($owner)->get(route('record-changes.evidence', ['request' => $request->id, 'which' => 'before']))->assertNotFound();
    $this->actingAs($manager);
    app()->forgetInstance(Access::class);
    $this->get(route('record-changes.evidence', ['request' => $request->id, 'which' => 'before']))->assertOk();
    expect(fn () => app(ReviewRecordChange::class)->handle($managerAccess, $request->id, true))
        ->toThrow(RuntimeException::class, 'Document changes must be made by management.');
    expect($request->refresh()->status)->toBe('PENDING')->and($document->fresh())->not->toBeNull()
        ->and(EmployeeEmploymentHistory::query()->count())->toBe(0);
    if ($withdraw) {
        app(WithdrawRecordChange::class)->handle($ownerAccess, $request->id);
    } else {
        app(ReviewRecordChange::class)->handle($managerAccess, $request->id, false, 'Management will handle this document.');
    }
    expect($request->refresh()->status)->toBe($withdraw ? 'WITHDRAWN' : 'REJECTED');
    foreach (['baseline', 'proposed', 'form', 'changes', 'reason', 'requested_by_name'] as $key) {
        expect($request->getRawOriginal($key))->toBe($values[$key]);
    }
    app(DeleteDocument::class)->handle($managerAccess, $employee->id, $document->id);
    Storage::disk('local')->assertExists($document->path);
    expect(app(RecordChangeStorageUsage::class)->bytes())->toBe($document->size);
})->with([false, true]);

it('pins profile history to the linked employee after client filter changes', function () {
    $employee = recordDocsEmployee();
    $other = recordDocsEmployee();
    $owner = recordDocsUser($employee, [ModuleCode::PROFILE, ModuleCode::EMPLOYEES], true);
    $this->actingAs($owner);
    Livewire::test(History::class, ['own' => true])->set('employeeId', $other->id)
        ->assertSet('employeeId', $employee->id)->assertDontSee('All employees');
});

it('keeps Documents out of Profile mode even for a manager linked to the employee', function () {
    $employee = recordDocsEmployee();
    $manager = recordDocsUser($employee, [ModuleCode::PROFILE, ModuleCode::EMPLOYEES], true);
    $actor = Access::forActor($manager);
    $this->actingAs($manager);
    Livewire::test(Documents::class, ['employeeId' => $employee->id, 'own' => true])->assertForbidden();
    expect(fn () => app(SubmitRecordDocument::class)->attach($actor, UploadedFile::fake()->create('private.pdf', 2), [], ''))
        ->toThrow(HttpException::class);
    expect(fn () => app(SubmitRecordDocument::class)->delete($actor, 'missing', ''))->toThrow(HttpException::class);
    expect(EmployeeDocument::query()->count())->toBe(0)
        ->and(EmployeeRecordChangeRequest::query()->count())->toBe(0);
});

it('rechecks current management access before document writes and discards refused upload bytes', function (string $revocation) {
    Storage::fake('local');
    $employee = recordDocsEmployee();
    $manager = recordDocsUser($employee, [ModuleCode::EMPLOYEES], true);
    $document = recordDocsOfficial($employee, $manager);
    $actor = Access::forActor($manager);
    $auditCount = AuditLog::query()->count();
    match ($revocation) {
        'inactive' => $manager->forceFill(['is_active' => false])->save(),
        'grant' => RoleModuleGrant::query()->where('role_id', $manager->role_id)->delete(),
        'all-data' => Role::query()->whereKey($manager->role_id)->update(['all_data' => false]),
        default => throw new InvalidArgumentException('Unknown management access revocation.'),
    };
    if ($revocation !== 'inactive') {
        $actor = Access::forActor($manager->fresh());
    }
    expect(fn () => app(AttachDocument::class)->handle($actor, $employee->id, UploadedFile::fake()->create('refused.pdf', 2),
        ['name' => 'Refused upload', 'document_no' => '', 'category' => 'Document', 'expires_on' => '']))
        ->toThrow($revocation === 'inactive' ? RuntimeException::class : HttpException::class);
    expect(fn () => app(DeleteDocument::class)->handle($actor, $employee->id, $document->id))->toThrow(HttpException::class);
    expect(EmployeeDocument::query()->count())->toBe(1)
        ->and(AuditLog::query()->count())->toBe($auditCount)
        ->and(Storage::disk('local')->allFiles('employee-documents'))->toBe([$document->path]);
})->with(['inactive', 'grant', 'all-data']);
