<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Employees\Documents;
use App\Livewire\Employees\EmployeeFields;
use App\Livewire\Projects\ProjectsData;
use App\Livewire\Projects\Show;
use App\Livewire\Tasks\MyTasksPage;
use App\Models\AuditLog;
use App\Models\CommunityAttachment;
use App\Models\CommunityPost;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\EmployeeProfile;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskFile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Social\Application\Attachments;
use App\Platform\Files\AttachmentUsage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Upload limits — ONE source of truth for every upload surface.
 *
 * The per-file cap and the storage ceiling are `config('copems.uploads.*')`
 * (COPEMS_MAX_UPLOAD_BYTES / COPEMS_STORAGE_CEILING_BYTES), read by the
 * Documents card, the My Tasks SUBMISSION path and the Projects BRIEF path
 * alike. A surface that read its own 2 MB / 1 GB constant instead of the
 * config would silently stay behind when the env var moves the others.
 *
 * Every test here lowers the config to a few bytes and proves the surface
 * refuses a file the 2 MB default would have accepted — and stores one under
 * the lowered cap, so a surface that refuses everything cannot pass. The
 * Documents card is covered at BOTH its pick hook and its submit backstop,
 * because the two are separate reads and the backstop is what protects an
 * action called without the page. At the defaults nothing changes: the last
 * test pins the config defaults to the fallback constants.
 *
 * Community and Chat read the same per-file cap through
 * Attachments::maxFileBytes(), but keep their OWN ceiling,
 * COPEMS_SOCIAL_STORAGE_CEILING_BYTES: their bytes never fill the one
 * documents and task files share, and those never fill theirs. Both the
 * pick-time check and the store's re-check under the budget lock count
 * Community and Chat's bytes alone.
 */

/** @param list<string> $modules ModuleCode constants to grant */
function uplimRole(array $modules, bool $allData): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'Uplim '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();

    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    return $role;
}

/** @param list<string> $modules ModuleCode constants to grant */
function uplimActor(array $modules, bool $allData, ?string $employeeId = null): User
{
    $user = User::factory()->create([
        'role_id' => uplimRole($modules, $allData)->getKey(),
        'employee_id' => $employeeId,
    ]);

    test()->actingAs($user);

    return $user;
}

function uplimEmployee(string $code = 'COP-90001'): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => $code,
        'first_name' => 'Liza',
        'last_name' => 'Domingo',
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => true,
    ])->save();

    return $employee;
}

/**
 * A fake upload of EXACTLY $bytes — `UploadedFile::fake()->create()` only
 * takes whole kilobytes, which cannot straddle a 1,000-byte cap.
 */
function uplimFile(string $name, int $bytes): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, str_repeat('x', $bytes));
}

/** A PENDING task on a fresh project, plus a PROJECTS manager acting. */
function uplimManagedTask(): ProjectTask
{
    $project = new Project;
    $project->forceFill([
        'code' => 'PRJ-901',
        'name' => 'Upload limits',
        'status' => 'ACTIVE',
        'start_date' => '2026-01-01',
    ])->save();

    $task = new ProjectTask;
    $task->forceFill([
        'code' => 'TSK-901',
        'project_id' => $project->id,
        'title' => 'Brief target',
        'status' => 'PENDING',
        'priority' => 'NORMAL',
    ])->save();

    uplimActor([ModuleCode::PROJECTS], allData: true);

    return $task;
}

/* |---| Employee documents |---| */

it('rejects an over-cap document at the pick hook using the configured cap', function () {
    Storage::fake('local');
    config()->set('copems.uploads.max_file_bytes', 1000);

    $employee = uplimEmployee();
    uplimActor([ModuleCode::EMPLOYEES], allData: true);

    Livewire::test(Documents::class, ['employeeId' => $employee->id])
        ->set('upload', uplimFile('over.pdf', 1500))
        // The pick hook toasts the configured cap, not a hard-coded 2 MB.
        ->assertDispatched('toast-show', fn (string $name, array $params): bool => ($params['dataset']['variant'] ?? null) === 'danger'
            && ($params['slots']['text'] ?? null) === 'over.pdf is 2 KB — the limit is 1 KB per file.')
        ->assertSet('upload', null)
        // And the rendered card quotes the same cap.
        ->assertSee('Max 1 KB per file.');

    expect(EmployeeDocument::query()->count())->toBe(0);
});

it('rejects an over-cap document at submit even when the pick hook let it through', function () {
    Storage::fake('local');
    DocumentType::query()->create(['code' => 'DOC-001', 'name' => 'Contract']);

    $employee = uplimEmployee();
    uplimActor([ModuleCode::EMPLOYEES], allData: true);

    // Picked under the 2 MB default, so the pick hook accepts it and stages it...
    $component = Livewire::test(Documents::class, ['employeeId' => $employee->id])
        ->set('form.category', 'Contract')
        ->set('upload', uplimFile('over.pdf', 1500))
        ->assertSet('form.name', 'over');

    // ...then the cap drops before submit, which is the backstop's whole job.
    config()->set('copems.uploads.max_file_bytes', 1000);

    $component->call('submit')
        ->assertDispatched('toast-show', fn (string $name, array $params): bool => ($params['dataset']['variant'] ?? null) === 'danger'
            && ($params['slots']['text'] ?? null) === 'The uploaded file is larger than the 1 KB limit.');

    expect(EmployeeDocument::query()->count())->toBe(0);
    expect(AuditLog::query()->where('action', 'DOCUMENT_UPLOADED')->exists())->toBeFalse();
    expect(Storage::disk('local')->allFiles('employee-documents'))->toBe([]);

    // Positive control at the SAME lowered cap: a file under it is stored.
    Livewire::test(Documents::class, ['employeeId' => $employee->id])
        ->set('form.category', 'Contract')
        ->set('upload', uplimFile('small.pdf', 900))
        ->call('submit');

    $document = EmployeeDocument::query()->where('employee_id', $employee->id)->first();
    expect($document)->not->toBeNull();
    expect($document->size)->toBe(900);
    Storage::disk('local')->assertExists($document->path);
});

/* |---| My Tasks — SUBMISSION evidence |---| */

it('applies the configured cap to a My Tasks submission file', function () {
    Storage::fake('local');
    config()->set('copems.uploads.max_file_bytes', 1000);

    $employee = uplimEmployee();
    $project = new Project;
    $project->forceFill(['code' => 'PRJ-902', 'name' => 'Evidence', 'status' => 'ACTIVE', 'start_date' => '2026-01-01'])->save();
    $task = new ProjectTask;
    $task->forceFill([
        'code' => 'TSK-902',
        'project_id' => $project->id,
        'title' => 'Submit evidence',
        'status' => 'IN_PROGRESS',
        'priority' => 'NORMAL',
    ])->save();
    $task->syncAssignees([$employee->id], null);

    uplimActor([ModuleCode::TASKS], allData: false, employeeId: $employee->id);

    Livewire::test(MyTasksPage::class)
        ->call('openTask', $task->id)
        ->set('upload', uplimFile('over.pdf', 1500))
        ->assertDispatched('toast-show', fn (string $name, array $params): bool => ($params['dataset']['variant'] ?? null) === 'danger'
            && ($params['slots']['text'] ?? null) === 'over.pdf is 2 KB — the limit is 1 KB per file.');

    expect(ProjectTaskFile::query()->where('task_id', $task->id)->count())->toBe(0);

    Livewire::test(MyTasksPage::class)
        ->call('openTask', $task->id)
        ->set('upload', uplimFile('small.pdf', 900));

    $file = ProjectTaskFile::query()->where('task_id', $task->id)->first();
    expect($file)->not->toBeNull();
    expect($file->size)->toBe(900);
});

/* |---| Projects — BRIEF files |---| */

it('applies the configured cap to a BRIEF file attached from the review dialog', function () {
    Storage::fake('local');
    config()->set('copems.uploads.max_file_bytes', 1000);

    $task = uplimManagedTask();

    Livewire::test(Show::class, ['project' => $task->project_id])
        ->call('openTask', $task->id)
        ->set('reviewBriefUpload', uplimFile('over.pdf', 1500))
        ->assertDispatched('toast-show', fn (string $name, array $params): bool => ($params['dataset']['variant'] ?? null) === 'danger'
            && ($params['slots']['text'] ?? null) === 'over.pdf is 2 KB — the limit is 1 KB per file.');

    expect(ProjectTaskFile::query()->where('task_id', $task->id)->count())->toBe(0);
    expect(AuditLog::query()->where('entity', 'project_task_files')->exists())->toBeFalse();

    Livewire::test(Show::class, ['project' => $task->project_id])
        ->call('openTask', $task->id)
        ->set('reviewBriefUpload', uplimFile('small.pdf', 900));

    $file = ProjectTaskFile::query()->where('task_id', $task->id)->first();
    expect($file)->not->toBeNull();
    expect($file->kind)->toBe('BRIEF');
    expect($file->size)->toBe(900);
});

it('applies the configured cap to staged brief files at the pick hook and again on save', function () {
    Storage::fake('local');

    $task = uplimManagedTask();
    $projectId = $task->project_id;

    // Pick hook: under a lowered cap the file never reaches the stage.
    config()->set('copems.uploads.max_file_bytes', 1000);
    Livewire::test(Show::class, ['project' => $projectId])
        ->set('briefPick', [uplimFile('over.pdf', 1500)])
        ->assertSet('briefStage', [])
        ->assertDispatched('toast-show', fn (string $name, array $params): bool => ($params['dataset']['variant'] ?? null) === 'danger'
            && ($params['slots']['text'] ?? null) === 'over.pdf is 2 KB — the limit is 1 KB per file.');

    // Backstop: staged under the 2 MB default, then the cap drops before
    // save — the task is still created, but the file is refused.
    config()->set('copems.uploads.max_file_bytes', 2_000_000);
    $component = Livewire::test(Show::class, ['project' => $projectId])
        ->set('briefPick', [uplimFile('over.pdf', 1500)])
        ->assertCount('briefStage', 1);

    config()->set('copems.uploads.max_file_bytes', 1000);
    $component
        ->set('taskTitle', 'Task with a stale brief')
        ->call('saveTask')
        ->assertDispatched('toast-show', fn (string $name, array $params): bool => ($params['dataset']['variant'] ?? null) === 'danger'
            && str_contains((string) ($params['slots']['text'] ?? ''), 'over.pdf could not be attached: over.pdf is 2 KB — the limit is 1 KB per file.'));

    $created = ProjectTask::query()->where('project_id', $projectId)->where('title', 'Task with a stale brief')->first();
    expect($created)->not->toBeNull();
    expect(ProjectTaskFile::query()->where('task_id', $created->id)->count())->toBe(0);
    expect(AuditLog::query()->where('action', 'TASK_BRIEF_ATTACHED')->exists())->toBeFalse();
});

it('applies the configured storage ceiling to a BRIEF file', function () {
    Storage::fake('local');
    // The ceiling is the SUM of documents and task files — 800 bytes already
    // used, so a 500-byte brief overflows a 1,000-byte ceiling.
    config()->set('copems.uploads.storage_ceiling_bytes', 1000);

    $task = uplimManagedTask();

    $document = new EmployeeDocument;
    $document->fill([
        'employee_id' => uplimEmployee('COP-90002')->id,
        'name' => 'Existing',
        'category' => 'Contract',
        'file_name' => 'existing.pdf',
        'mime' => 'application/pdf',
        'size' => 800,
        'path' => 'employee-documents/existing.pdf',
    ]);
    $document->save();

    Livewire::test(Show::class, ['project' => $task->project_id])
        ->call('openTask', $task->id)
        ->set('reviewBriefUpload', uplimFile('brief.pdf', 500))
        ->assertDispatched('toast-show', fn (string $name, array $params): bool => ($params['dataset']['variant'] ?? null) === 'danger'
            && ($params['slots']['text'] ?? null) === 'The file store is full. Delete old attachments before uploading more.');

    expect(ProjectTaskFile::query()->where('task_id', $task->id)->count())->toBe(0);

    // Room for it once the ceiling is raised.
    config()->set('copems.uploads.storage_ceiling_bytes', 2000);
    Livewire::test(Show::class, ['project' => $task->project_id])
        ->call('openTask', $task->id)
        ->set('reviewBriefUpload', uplimFile('brief.pdf', 500));

    expect(ProjectTaskFile::query()->where('task_id', $task->id)->count())->toBe(1);
});

/** Community and Chat already hold `$bytes`: one post photo's row, which is all the budget counts. */
function uplimSocialBytes(int $bytes): void
{
    $post = new CommunityPost;
    $post->forceFill(['body' => 'Budget '.Str::random(6)])->save();

    $photo = new CommunityAttachment;
    $photo->forceFill([
        'post_id' => $post->getKey(),
        'kind' => 'PHOTO',
        'path' => 'community-photos/budget-'.Str::random(8).'.png',
        'mime' => 'image/png',
        'size' => $bytes,
        'position' => 0,
    ])->save();
}

/* |---| Community and Chat's own budget |---| */

it('keeps Community and Chat files out of the ceiling documents and task files share', function () {
    Storage::fake('local');
    // Community and Chat hold 900 bytes: with a 500-byte brief that would
    // overflow this 1,000-byte ceiling — if they counted in it.
    config()->set('copems.uploads.storage_ceiling_bytes', 1000);
    uplimSocialBytes(900);
    $task = uplimManagedTask();

    expect(AttachmentUsage::bytes())->toBe(0);

    Livewire::test(Show::class, ['project' => $task->project_id])
        ->call('openTask', $task->id)
        ->set('reviewBriefUpload', uplimFile('brief.pdf', 500));

    expect(ProjectTaskFile::query()->where('task_id', $task->id)->count())->toBe(1)
        ->and(AttachmentUsage::bytes())->toBe(500);
});

it('holds Community and Chat to their own ceiling, whatever documents and task files use', function () {
    Storage::fake('local');
    $photo = UploadedFile::fake()->image('budget.png', 12, 12);
    $size = (int) $photo->getSize();

    // Documents fill the shared ceiling to the brim.
    config()->set('copems.uploads.storage_ceiling_bytes', 1000);
    $document = new EmployeeDocument;
    $document->fill([
        'employee_id' => uplimEmployee('COP-90003')->id,
        'name' => 'Full',
        'category' => 'Contract',
        'file_name' => 'full.pdf',
        'mime' => 'application/pdf',
        'size' => 1000,
        'path' => 'employee-documents/full.pdf',
    ]);
    $document->save();
    uplimSocialBytes(700);

    // One byte short of Community and Chat's own ceiling: refused at the
    // pick-time check, and again by the store's re-check under the lock.
    config()->set('copems.uploads.social_storage_ceiling_bytes', 700 + $size - 1);
    expect(fn () => Attachments::check([$photo]))
        ->toThrow(RuntimeException::class, 'Community and Chat storage is full. Delete old photos and files before uploading more.');
    expect(Attachments::FULL)->toBe('Community and Chat storage is full. Delete old photos and files before uploading more.');
    $stored = [];
    expect(fn () => Attachments::store($photo, 'community', static fn (array $meta): array => $meta, $stored))
        ->toThrow(RuntimeException::class, Attachments::FULL);
    expect(Storage::disk('local')->allFiles('community-photos'))->toBe([]);

    // Exactly enough room: the documents' full ceiling does not matter.
    config()->set('copems.uploads.social_storage_ceiling_bytes', 700 + $size);
    Attachments::check([$photo]);
    $meta = Attachments::store($photo, 'community', static fn (array $meta): array => $meta, $stored);

    expect($meta['size'])->toBe($size)
        ->and($meta['path'])->toStartWith('community-photos/');
    Storage::disk('local')->assertExists($meta['path']);
});

/* |---| Defaults |---| */

it('reads identical defaults on every surface, equal to the fallback constants', function () {
    // config/copems.php ships the reference's 2 MB / 1 GB, and .env.example
    // sets neither key — so a host that never sets them gets the fallback
    // constants' limits on every surface.
    expect(EmployeeFields::maxFileBytes())->toBe(EmployeeFields::MAX_FILE_BYTES)
        ->and(ProjectsData::maxFileBytes())->toBe(ProjectsData::MAX_FILE_BYTES)
        ->and(EmployeeFields::maxFileBytes())->toBe(2_000_000)
        ->and(ProjectsData::maxFileBytes())->toBe(2_000_000)
        ->and(EmployeeFields::storageCeilingBytes())->toBe(1_000_000_000)
        ->and(ProjectsData::storageCeilingBytes())->toBe(ProjectsData::STORAGE_CEILING_BYTES)
        ->and(Attachments::maxFileBytes())->toBe(2_000_000)
        ->and(Attachments::storageCeilingBytes())->toBe(500_000_000);

    // And a change to the one key reaches both helper classes.
    config()->set('copems.uploads.max_file_bytes', 1000);
    config()->set('copems.uploads.storage_ceiling_bytes', 5000);

    expect(EmployeeFields::maxFileBytes())->toBe(1000)
        ->and(ProjectsData::maxFileBytes())->toBe(1000)
        ->and(Attachments::maxFileBytes())->toBe(1000)
        ->and(EmployeeFields::storageCeilingBytes())->toBe(5000)
        ->and(ProjectsData::storageCeilingBytes())->toBe(5000)
        // Community and Chat's ceiling is its own key: the shared one leaves it alone.
        ->and(Attachments::storageCeilingBytes())->toBe(500_000_000);

    config()->set('copems.uploads.social_storage_ceiling_bytes', 7000);
    expect(Attachments::storageCeilingBytes())->toBe(7000)
        ->and(EmployeeFields::storageCeilingBytes())->toBe(5000);
});
