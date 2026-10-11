<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeDocument;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Workforce\Application\Commands\UpdatePortrait;
use App\Platform\Files\AttachmentStore;
use App\Platform\Files\FileConsistency;
use App\Support\Access;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** A refused metadata write must not destroy an existing photo or consume new attachment storage. */
function fileReliabilityEmployee(): EmployeeProfile
{
    return EmployeeProfile::create(['employee_code' => 'COP-FILE', 'first_name' => 'File', 'last_name' => 'Safety', 'date_hired' => '2024-01-01', 'employment_status' => 'Regular', 'is_active' => true, 'photo_path' => 'portraits/old.jpg']);
}

function fileReliabilityActor(): Access
{
    $role = Role::create(['name' => 'Portrait reviewer', 'all_data' => true, 'is_system' => false]);
    RoleModuleGrant::create(['role_id' => $role->id, 'module' => ModuleCode::EMPLOYEES]);

    return Access::forActor(User::factory()->create(['role_id' => $role->id]));
}

it('preserves the old photo when replacement metadata cannot save', function () {
    Storage::fake('public');
    $employee = fileReliabilityEmployee();
    $actor = fileReliabilityActor();
    Storage::disk('public')->put('portraits/old.jpg', 'old bytes');
    EmployeeProfile::updating(static function (): never {
        throw new RuntimeException('metadata refused');
    });
    expect(fn () => app(UpdatePortrait::class)->handle($actor, $employee->id, UploadedFile::fake()->image('new.png')))->toThrow(RuntimeException::class, 'metadata refused');
    expect(Storage::disk('public')->allFiles())->toBe(['portraits/old.jpg']);
    expect(Storage::disk('public')->get('portraits/old.jpg'))->toBe('old bytes');
    expect($employee->fresh()->photo_path)->toBe('portraits/old.jpg');
});

it('uses a distinct portrait path and removes the old image after commit', function () {
    Storage::fake('public');
    $employee = fileReliabilityEmployee();
    Storage::disk('public')->put('portraits/old.jpg', 'old bytes');
    expect(app(UpdatePortrait::class)->handle(fileReliabilityActor(), $employee->id, UploadedFile::fake()->image('new.png')))->toBeTrue();
    $path = $employee->fresh()->photo_path;
    expect($path)->toStartWith('portraits/'.$employee->id.'/')->toEndWith('.jpg');
    Storage::disk('public')->assertExists($path);
    Storage::disk('public')->assertMissing('portraits/old.jpg');
});

it('rechecks the shared quota inside the attachment transaction before writing bytes', function () {
    Storage::fake('local');
    $employee = fileReliabilityEmployee();
    EmployeeDocument::create(['employee_id' => $employee->id, 'name' => 'Existing', 'category' => 'Evidence', 'file_name' => 'existing.pdf', 'mime' => 'application/pdf', 'size' => 1024, 'path' => 'employee-documents/existing.pdf']);
    expect(fn () => app(AttachmentStore::class)->store(UploadedFile::fake()->create('new.pdf', 1), 'employee-documents', 'new.pdf', static function (): never {
        throw new LogicException('Quota rejection must happen before the persistence callback.');
    }, ceiling: 1500))->toThrow(RuntimeException::class, 'The file store is full.');
    expect(EmployeeDocument::count())->toBe(1);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('reports interrupted file writes without deleting either stored or unreferenced files', function () {
    Storage::fake('local');
    Storage::fake('public');
    fileReliabilityEmployee();
    $disk = Storage::disk('local');
    $disk->put('employee-documents/orphan.pdf', 'orphan');
    $disk->put('employee-documents/in-flight.pdf', 'new upload');
    touch($disk->path('employee-documents/orphan.pdf'), time() - 172800);
    $report = app(FileConsistency::class)->inspect();
    expect($report['missing'])->toBe([['disk' => 'public', 'path' => 'portraits/old.jpg']]);
    expect($report['unreferenced'])->toBe([['disk' => 'local', 'path' => 'employee-documents/orphan.pdf']]);
    $disk->assertExists(['employee-documents/orphan.pdf', 'employee-documents/in-flight.pdf']);
    expect(Artisan::call('ems:check-files'))->toBe(1);
});

it('fails closed when the storage lock row is missing', function () {
    Storage::fake('local');
    DB::table('locks')->where('name', 'copems:attachment-budget')->delete();
    expect(fn () => app(AttachmentStore::class)->store(UploadedFile::fake()->create('new.pdf', 1), 'employee-documents', 'new.pdf', static fn (): bool => true, ceiling: 1500))->toThrow(RuntimeException::class, 'The attachment budget lock is missing.');
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('can retry the attachment lock migration and undo only its own sentinel', function () {
    $migration = require database_path('migrations/2026_09_17_0001_add_attachment_budget_lock.php');
    $migration->up();
    $migration->up();
    expect(DB::table('locks')->where('name', 'copems:attachment-budget')->count())->toBe(1);
    $migration->down();
    expect(DB::table('locks')->where('name', 'copems:payroll-membership')->exists())->toBeTrue();
    $migration->up();
});
