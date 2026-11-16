<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Inventory\CountsPage;
use App\Livewire\Inventory\DisposalsPage;
use App\Livewire\Inventory\IncidentsPage;
use App\Livewire\Inventory\SignedReceipt;
use App\Livewire\Inventory\Support\CountsExport;
use App\Livewire\Inventory\Support\DisposalsExport;
use App\Livewire\Inventory\Support\IncidentsExport;
use App\Models\EmployeeProfile;
use App\Models\InventoryAttachment;
use App\Models\InventoryCount;
use App\Models\InventoryCountLine;
use App\Models\InventoryDisposal;
use App\Models\InventoryIncident;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryReceipt;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Inventory\Application\Commands\AttachSignedReceipt;
use App\Modules\Inventory\Application\Commands\DeleteSignedReceipt;
use App\Modules\Inventory\Application\Commands\IssueItems;
use App\Modules\Inventory\Application\Commands\RecordCount;
use App\Modules\Inventory\Application\Commands\RecordDisposal;
use App\Modules\Inventory\Application\Commands\RecordIncident;
use App\Modules\Inventory\Application\Commands\ResolveCount;
use App\Modules\Inventory\Application\Commands\ResolveIncident;
use App\Modules\Inventory\Application\Commands\SaveItem;
use App\Modules\Inventory\Application\Queries\InventoryAttachments;
use App\Modules\Inventory\Application\Queries\InventoryLifecycle;
use App\Platform\Files\AttachmentUsage;
use App\Support\Access;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-08 02:00:00', 'UTC'));
});

/** Inventory observations and signed evidence cannot silently release custody or widen employee access. */
function invlifeEmployee(): EmployeeProfile
{
    $row = new EmployeeProfile;
    $row->forceFill(['employee_code' => 'INV-'.Str::random(8), 'first_name' => 'Inventory', 'last_name' => Str::random(8), 'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true])->save();

    return $row;
}

/** @param list<string> $modules */
function invlifeUser(?EmployeeProfile $employee = null, bool $allData = true, array $modules = [ModuleCode::INVENTORY]): User
{
    $role = new Role;
    $role->forceFill(['name' => 'Inventory lifecycle '.Str::random(8), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->id, 'module' => $module])->save();
    }

    return User::factory()->create(['role_id' => $role->id, 'employee_id' => $employee?->id]);
}

function invlifeItem(Access $actor, ?InventoryLocation $location = null): InventoryItem
{
    $id = app(SaveItem::class)->handle($actor, null, ['code' => 'LIFE-'.Str::random(8), 'name' => 'Test printer', 'acquired_on' => '2026-01-01', 'cost' => '1000', 'location_id' => $location?->id]);

    return InventoryItem::query()->findOrFail($id);
}

/** @return array{Access, Access, InventoryItem, EmployeeProfile, string} */
function invlifeIssued(): array
{
    $actor = Access::forActor(invlifeUser());
    $reviewer = Access::forActor(invlifeUser());
    $employee = invlifeEmployee();
    $issuer = invlifeEmployee();
    $item = invlifeItem($actor);
    $receipt = app(IssueItems::class)->handle($actor, [$item->id], $employee->id, $issuer->id, '2026-10-01', 'FIX-'.Str::random(12));

    return [$actor, $reviewer, $item->fresh(), $employee, $receipt];
}

it('records loss and a review without changing custody or the issued MR snapshot', function () {
    [$actor, $reviewer, $item, $employee, $receipt] = invlifeIssued();
    $original = InventoryReceipt::query()->findOrFail($receipt)->toArray();
    $id = app(RecordIncident::class)->save($actor, ['item_id' => $item->id, 'kind' => 'LOSS', 'reported_on' => '2026-10-02', 'reference' => 'LOSS-1', 'details' => 'Not located after office search.']);
    expect($item->fresh()->condition)->toBe('MISSING')->and($item->fresh()->employee_id)->toBe($employee->id)->and($item->fresh()->current_receipt_id)->toBe($receipt)->and(InventoryReceipt::query()->findOrFail($receipt)->toArray())->toBe($original);
    app(ResolveIncident::class)->save($reviewer, $id, ['condition' => 'SERVICEABLE', 'resolution_reference' => 'REV-1', 'resolution' => 'Located and verified by the reviewer.']);
    expect($item->fresh()->condition)->toBe('SERVICEABLE')->and($item->fresh()->employee_id)->toBe($employee->id)->and(InventoryIncident::query()->findOrFail($id)->reviewed_by)->toBe((int) $reviewer->userId);
    expect(fn () => app(ResolveIncident::class)->save($reviewer, $id, ['condition' => 'MISSING', 'resolution_reference' => 'REV-2', 'resolution' => 'Duplicate review.']))->toThrow(RuntimeException::class, 'already');
});

it('refuses reports from self-scoped and ungranted actors and rejects invalid dates or duplicate open reports', function () {
    [$actor, , $item] = invlifeIssued();
    $input = ['item_id' => $item->id, 'kind' => 'DAMAGE', 'reported_on' => '2026-10-02', 'reference' => 'DMG-1', 'details' => 'Broken display.'];
    foreach ([Access::forGuest(), Access::forActor(invlifeUser(null, false)), Access::forActor(invlifeUser(null, true, [ModuleCode::EMPLOYEES]))] as $denied) {
        expect(fn () => app(RecordIncident::class)->save($denied, $input))->toThrow(RuntimeException::class);
    }
    expect(fn () => app(RecordIncident::class)->save($actor, array_replace($input, ['reported_on' => '2099-01-01'])))->toThrow(RuntimeException::class);
    expect(fn () => app(RecordIncident::class)->save($actor, array_replace($input, ['reported_on' => '2026-09-01'])))->toThrow(RuntimeException::class);
    expect(InventoryIncident::query()->count())->toBe(0);
    app(RecordIncident::class)->save($actor, $input);
    expect(fn () => app(RecordIncident::class)->save($actor, $input))->toThrow(RuntimeException::class, 'open report');
    expect(InventoryIncident::query()->count())->toBe(1);
});

it('requires reviewed unserviceable stock and a private authorization document before disposal', function () {
    Storage::fake('local');
    $actor = Access::forActor(invlifeUser());
    $reviewer = Access::forActor(invlifeUser());
    $authority = invlifeEmployee();
    $item = invlifeItem($actor);
    $input = ['item_id' => $item->id, 'disposed_on' => '2026-10-08', 'method' => 'Authorized recycling', 'reason' => 'Beyond economical repair', 'authorization_reference' => 'AUTH-1', 'authorized_by_employee_id' => $authority->id];
    expect(fn () => app(RecordDisposal::class)->save($actor, $input, UploadedFile::fake()->create('approval.pdf', 1, 'application/pdf')))->toThrow(RuntimeException::class, 'unserviceable');
    expect(Storage::disk('local')->allFiles())->toBe([]);
    $incident = app(RecordIncident::class)->save($actor, ['item_id' => $item->id, 'kind' => 'INSPECTION', 'reported_on' => '2026-10-02', 'reference' => 'INS-1', 'details' => 'Inspection found irreparable damage.']);
    expect(fn () => app(RecordDisposal::class)->save($actor, $input, UploadedFile::fake()->create('approval.pdf', 1, 'application/pdf')))->toThrow(RuntimeException::class, 'open condition');
    app(ResolveIncident::class)->save($reviewer, $incident, ['condition' => 'UNSERVICEABLE', 'resolution_reference' => 'CHECK-1', 'resolution' => 'Independent inspection confirmed.']);
    $id = app(RecordDisposal::class)->save($actor, $input, UploadedFile::fake()->create('approval.pdf', 1, 'application/pdf'));
    expect($item->fresh()->status)->toBe('DISPOSED')->and($item->fresh()->employee_id)->toBeNull()->and(InventoryDisposal::query()->findOrFail($id)->authorized_by_employee_id)->toBe($authority->id);
    $file = InventoryAttachment::query()->where('disposal_id', $id)->firstOrFail();
    Storage::disk('local')->assertExists($file->path);
    expect(fn () => app(DeleteSignedReceipt::class)->delete($actor, $file->id))->toThrow(RuntimeException::class, 'retained');
    expect(fn () => app(RecordDisposal::class)->save($actor, $input, UploadedFile::fake()->create('approval.pdf', 1, 'application/pdf')))->toThrow(RuntimeException::class);
    expect(InventoryDisposal::query()->count())->toBe(1)->and(InventoryAttachment::query()->count())->toBe(1);
});

it('never disposes an item still issued to an employee', function () {
    Storage::fake('local');
    [$actor, $reviewer, $item] = invlifeIssued();
    $id = app(RecordIncident::class)->save($actor, ['item_id' => $item->id, 'kind' => 'INSPECTION', 'reported_on' => '2026-10-02', 'reference' => 'INS-ISSUED', 'details' => 'Inspection.']);
    app(ResolveIncident::class)->save($reviewer, $id, ['condition' => 'UNSERVICEABLE', 'resolution_reference' => 'REV-ISSUED', 'resolution' => 'Confirmed.']);
    expect(fn () => app(RecordDisposal::class)->save($actor, ['item_id' => $item->id, 'disposed_on' => '2026-10-08', 'method' => 'Recycling', 'reason' => 'Unserviceable', 'authorization_reference' => 'AUTH-2', 'authorized_by_employee_id' => invlifeEmployee()->id], UploadedFile::fake()->create('approval.pdf', 1, 'application/pdf')))->toThrow(RuntimeException::class, 'unassigned');
    expect(InventoryDisposal::query()->count())->toBe(0)->and($item->fresh()->status)->toBe('ISSUED');
});

it('records count discrepancies and a review without changing item condition or custody', function () {
    $actor = Access::forActor(invlifeUser());
    $reviewer = Access::forActor(invlifeUser());
    $location = InventoryLocation::query()->create(['name' => 'Count office']);
    $item = invlifeItem($actor, $location);
    $line = app(InventoryLifecycle::class)->countSheet($actor, $location->id)->first();
    $input = ['location_id' => $location->id, 'counted_on' => '2026-10-02', 'reference' => 'COUNT-1', 'lines' => [['item_id' => $line->id, 'version' => $line->version, 'result' => 'NOT_FOUND', 'notes' => 'Room and cupboard checked.']]];
    $id = app(RecordCount::class)->save($actor, $input);
    expect($item->fresh()->condition)->toBe('SERVICEABLE')->and($item->fresh()->status)->toBe('IN_STOCK')->and(InventoryCountLine::query()->where('count_id', $id)->value('result'))->toBe('NOT_FOUND');
    app(ResolveCount::class)->save($reviewer, $id, ['resolution_reference' => 'CR-1', 'resolution' => 'Located in adjacent room; move will be recorded separately.']);
    expect(InventoryCount::query()->findOrFail($id)->status)->toBe('RESOLVED')->and($item->fresh()->location_id)->toBe($location->id)->and($item->fresh()->condition)->toBe('SERVICEABLE');
});

it('rejects stale count sheets and preserves atomicity across invalid observations', function () {
    $actor = Access::forActor(invlifeUser());
    $location = InventoryLocation::query()->create(['name' => 'Count room']);
    $item = invlifeItem($actor, $location);
    $line = app(InventoryLifecycle::class)->countSheet($actor, $location->id)->first();
    $input = ['location_id' => $location->id, 'counted_on' => '2026-10-02', 'reference' => 'COUNT-2', 'lines' => [['item_id' => $line->id, 'version' => $line->version, 'result' => 'NOT_FOUND', 'notes' => '']]];
    expect(fn () => app(RecordCount::class)->save($actor, $input))->toThrow(RuntimeException::class, 'Explain');
    expect(InventoryCount::query()->count())->toBe(0)->and(InventoryCountLine::query()->count())->toBe(0);
    $item->location_id = InventoryLocation::query()->create(['name' => 'Other room'])->id;
    $item->save();
    $input['lines'][0]['notes'] = 'Not located';
    expect(fn () => app(RecordCount::class)->save($actor, $input))->toThrow(RuntimeException::class, 'changed');
    expect(InventoryCount::query()->count())->toBe(0);
});

it('records unexpected items only outside the counted location and requires discrepancy notes', function () {
    $actor = Access::forActor(invlifeUser());
    $location = InventoryLocation::query()->create(['name' => 'Unexpected room']);
    $item = invlifeItem($actor);
    $line = app(InventoryLifecycle::class)->unexpectedItem($actor, $item->code, $location->id);
    $input = ['location_id' => $location->id, 'counted_on' => '2026-10-02', 'reference' => 'COUNT-UNEXPECTED', 'lines' => [['item_id' => $line->id, 'version' => $line->version, 'result' => 'FOUND', 'notes' => 'Observed here.']]];
    expect(fn () => app(RecordCount::class)->save($actor, $input))->toThrow(RuntimeException::class, 'unexpected');
    $input['lines'][0]['result'] = 'UNEXPECTED';
    app(RecordCount::class)->save($actor, $input);
    expect($item->fresh()->location_id)->toBeNull()->and(InventoryCountLine::query()->value('result'))->toBe('UNEXPECTED');
});

it('stores signed evidence privately and keeps Portal downloads pinned to the employee even with all data', function () {
    Storage::fake('local');
    [$actor, , , $employee, $receipt] = invlifeIssued();
    $id = app(AttachSignedReceipt::class)->save($actor, $receipt, UploadedFile::fake()->create('signed.pdf', 1, 'application/pdf'));
    $row = InventoryAttachment::query()->findOrFail($id);
    expect($row->uploaded_by)->toBe((int) $actor->userId)->and(AttachmentUsage::bytes())->toBe(1024);
    Storage::disk('local')->assertExists($row->path);
    $own = Access::forActor(invlifeUser($employee, true, [ModuleCode::PORTAL, ModuleCode::INVENTORY]));
    $response = app(InventoryAttachments::class)->download($own, $id, true);
    expect($response->headers->get('x-content-type-options'))->toBe('nosniff')->and($response->headers->get('cache-control'))->toContain('private')->and($response->headers->get('content-disposition'))->toContain('attachment');
    foreach ([Access::forActor(invlifeUser(invlifeEmployee(), true, [ModuleCode::PORTAL, ModuleCode::INVENTORY])), Access::forActor(invlifeUser(null, true, [ModuleCode::PORTAL]))] as $foreign) {
        expect(fn () => app(InventoryAttachments::class)->download($foreign, $id, true))->toThrow(HttpException::class);
    }
    $employeeOnly = Access::forActor(invlifeUser($employee, false, [ModuleCode::PORTAL]));
    expect(fn () => app(AttachSignedReceipt::class)->save($employeeOnly, $receipt, UploadedFile::fake()->create('other.pdf', 1, 'application/pdf')))->toThrow(RuntimeException::class);
    expect(fn () => app(DeleteSignedReceipt::class)->delete($employeeOnly, $id))->toThrow(RuntimeException::class);
    app(DeleteSignedReceipt::class)->delete($actor, $id);
    expect(InventoryAttachment::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing($row->path);
});

it('rechecks MIME, file size and the shared ceiling at signed-evidence persistence', function () {
    Storage::fake('local');
    [$actor, , , , $receipt] = invlifeIssued();
    expect(fn () => app(AttachSignedReceipt::class)->save($actor, $receipt, UploadedFile::fake()->create('fake.pdf', 1, 'text/html')))->toThrow(RuntimeException::class, 'PDF');
    config(['copems.uploads.max_file_bytes' => 100]);
    expect(fn () => app(AttachSignedReceipt::class)->save($actor, $receipt, UploadedFile::fake()->create('signed.pdf', 1, 'application/pdf')))->toThrow(RuntimeException::class, 'limit');
    config(['copems.uploads.max_file_bytes' => 5000, 'copems.uploads.storage_ceiling_bytes' => 100]);
    expect(fn () => app(AttachSignedReceipt::class)->save($actor, $receipt, UploadedFile::fake()->create('signed.pdf', 1, 'application/pdf')))->toThrow(RuntimeException::class, 'full');
    expect(InventoryAttachment::query()->count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('exports exactly the lifecycle query filters and eagerly refuses unrelated all-data access', function () {
    [$actor, , $item] = invlifeIssued();
    app(RecordIncident::class)->save($actor, ['item_id' => $item->id, 'kind' => 'DAMAGE', 'reported_on' => '2026-10-02', 'reference' => 'MATCH-ME', 'details' => 'Damaged cable.']);
    $matched = app(IncidentsExport::class)->export($actor, ['search' => 'MATCH', 'status' => 'OPEN']);
    $rows = iterator_to_array(($matched->rows)());
    expect($rows)->toHaveCount(1)->and($rows[0][0])->toBe('MATCH-ME');
    $hidden = app(IncidentsExport::class)->export($actor, ['status' => 'RESOLVED']);
    expect(iterator_to_array(($hidden->rows)()))->toBe([]);
    foreach ([IncidentsExport::class, DisposalsExport::class, CountsExport::class] as $export) {
        expect(fn () => app($export)->export(Access::forActor(invlifeUser(null, true, [ModuleCode::EMPLOYEES])), []))->toThrow(HttpException::class);
    }
});

it('renders manager lifecycle screens and rejects forged employee write actions', function () {
    $manager = invlifeUser();
    $this->actingAs($manager);
    app()->forgetInstance(Access::class);
    Livewire::test(IncidentsPage::class)->assertSee('Condition and Loss')->call('openAdd')->assertSee('Record Condition or Loss');
    Livewire::test(DisposalsPage::class)->assertSee('Disposals')->call('openAdd')->assertSee('Approving Employee');
    Livewire::test(CountsPage::class)->assertSee('Physical Counts')->call('openAdd')->assertSee('Record Physical Count');
    [, , , $employee, $receipt] = invlifeIssued();
    $this->actingAs(invlifeUser($employee, false, [ModuleCode::PORTAL]));
    app()->forgetInstance(Access::class);
    Livewire::test(SignedReceipt::class, ['receiptId' => $receipt, 'own' => true])->call('attach')->assertForbidden();
    expect(InventoryAttachment::query()->count())->toBe(0);
});

it('returns forbidden for limited Inventory accounts on manager lifecycle routes and queries', function () {
    $user = invlifeUser(invlifeEmployee(), false);
    $this->actingAs($user);
    app()->forgetInstance(Access::class);
    foreach (['inventory.incidents', 'inventory.disposals', 'inventory.counts'] as $route) {
        $this->get(route($route))->assertForbidden();
    }
    $actor = Access::forActor($user);
    expect(fn () => app(InventoryLifecycle::class)->incidents($actor))->toThrow(HttpException::class);
    expect(fn () => app(InventoryLifecycle::class)->disposals($actor))->toThrow(HttpException::class);
    expect(fn () => app(InventoryLifecycle::class)->counts($actor))->toThrow(HttpException::class);
});

it('lets another account linked to the reporting employee review the report and the count', function () {
    $reporter = invlifeEmployee();
    $actor = Access::forActor(invlifeUser($reporter));
    $samePerson = Access::forActor(invlifeUser($reporter));
    $location = InventoryLocation::query()->create(['name' => 'Two-account review room']);
    $item = invlifeItem($actor, $location);
    $incident = app(RecordIncident::class)->save($actor, ['item_id' => $item->id, 'kind' => 'DAMAGE', 'reported_on' => '2026-10-02', 'reference' => 'TWO-ACCOUNT', 'details' => 'Damage report.']);
    $line = app(InventoryLifecycle::class)->countSheet($actor, $location->id)->first();
    $count = app(RecordCount::class)->save($actor, ['location_id' => $location->id, 'counted_on' => '2026-10-02', 'reference' => 'TWO-ACCOUNT-COUNT', 'lines' => [['item_id' => $line->id, 'version' => $line->version, 'result' => 'FOUND', 'notes' => '']]]);
    app(ResolveIncident::class)->save($samePerson, $incident, ['condition' => 'DAMAGED', 'resolution_reference' => 'SELF', 'resolution' => 'Reviewed through another account.']);
    app(ResolveCount::class)->save($samePerson, $count, ['resolution_reference' => 'SELF', 'resolution' => 'Reviewed through another account.']);
    expect(InventoryIncident::query()->findOrFail($incident)->status)->not->toBe('OPEN')->and(InventoryCount::query()->findOrFail($count)->status)->not->toBe('OPEN');
});

it('opens an item report from its detail link with locked custody context and preserves the generic report flow', function () {
    [$actor, , $item, $employee, $receiptId] = invlifeIssued();
    $location = InventoryLocation::query()->create(['name' => 'Report context room']);
    $item->location_id = $location->id;
    $item->save();
    $other = invlifeItem($actor);
    $this->actingAs(User::query()->findOrFail($actor->userId));
    app()->forgetInstance(Access::class);
    Livewire::withQueryParams(['item' => $item->id]);
    $page = Livewire::test(IncidentsPage::class)
        ->assertSet('dialogOpen', true)
        ->assertSet('contextItemId', $item->id)
        ->assertSet('itemId', $item->id)
        ->assertSee('Item being reported')
        ->assertSee($item->name)
        ->assertSee($employee->last_name)
        ->assertSee($location->name)
        ->assertSee(InventoryReceipt::query()->findOrFail($receiptId)->mr_number);
    // A generic selector draft cannot redirect a report opened for a fixed item.
    $page->set('itemId', $other->id)
        ->set('reference', 'CONTEXT-REPORT')
        ->set('details', 'Context report from the item record.')
        ->call('save')
        ->assertSet('dialogOpen', false);
    expect(InventoryIncident::query()->where('reference', 'CONTEXT-REPORT')->value('item_id'))->toBe($item->id)
        ->and($other->fresh()->condition)->toBe('SERVICEABLE');
    $page->call('openAdd')->assertSet('contextItemId', null)->assertSet('itemId', '')->assertSee('Find Item');
    expect(fn () => Livewire::test(IncidentsPage::class)->set('contextItemId', $other->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
    Livewire::withQueryParams([]);
});

it('refuses malformed and unavailable item report links and does not grant management access through a client id', function () {
    $manager = invlifeUser();
    $this->actingAs($manager);
    app()->forgetInstance(Access::class);
    $this->get(route('inventory.incidents', ['item' => 'missing-item']))->assertNotFound();
    $this->get(route('inventory.incidents', ['item' => ['invalid']]))->assertNotFound();
    $item = invlifeItem(Access::forActor($manager));
    $this->actingAs(invlifeUser(invlifeEmployee(), false));
    app()->forgetInstance(Access::class);
    $this->get(route('inventory.incidents', ['item' => $item->id]))->assertForbidden();
    expect(InventoryIncident::query()->count())->toBe(0);
});
