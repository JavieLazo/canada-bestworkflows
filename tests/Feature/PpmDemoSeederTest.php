<?php

declare(strict_types=1);

use App\Livewire\Projects\CapacityWorkspace;
use App\Models\Project;
use App\Models\ProjectAvailability;
use App\Models\ProjectBudget;
use App\Models\ProjectBudgetLine;
use App\Models\ProjectCashAllocation;
use App\Models\ProjectCashEntry;
use App\Models\ProjectClient;
use App\Models\ProjectPortfolio;
use App\Models\ProjectRecordFile;
use App\Models\ProjectReport;
use App\Models\ProjectReportSubmission;
use App\Models\ProjectTask;
use App\Models\ProjectTaskFile;
use App\Models\User;
use App\Modules\WorkManagement\Application\Commands\UpdateProject;
use App\Modules\WorkManagement\Application\ProjectDraft;
use App\Modules\WorkManagement\Application\Queries\ProjectFinancials;
use App\Modules\WorkManagement\Application\Queries\WorkRecords;
use App\Modules\WorkManagement\Domain\Tasks\TaskSchedule;
use App\Modules\WorkManagement\Infrastructure\WorkScope;
use App\Support\Access;
use Database\Seeders\PpmDemoRecords;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

beforeEach(function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-05T12:00:00+08:00'));
    Storage::fake('local');
});

/** @return array<string, string> */
function ppmDemoFingerprints(): array
{
    $hashes = [];
    foreach (['users', 'roles', 'role_module_grants', 'employee_profiles', 'project_portfolios', 'project_portfolio_members', 'project_clients', 'project_client_members',
        'projects', 'project_members', 'project_tasks', 'project_task_assignees', 'project_task_submissions', 'project_task_files', 'project_budgets', 'project_budget_lines',
        'project_cash_entries', 'project_cash_allocations', (new ProjectAvailability)->getTable(), 'project_task_efforts', 'project_reports', 'project_report_submissions', 'project_record_files'] as $table) {
        $hashes[$table] = hash('sha256', DB::table($table)->orderBy('id')->get()->toJson());
    }

    return $hashes;
}

it('adds a complete repeatable review pack while preserving existing work and later demo edits', function () {
    $owner = cpaUser(cpaEmployee('Owner'), allData: true);
    $legacy = cpaProject($owner, cpaClient($owner), 'Existing operational project');
    $task = cpaTask($owner, $legacy, [$owner->employee], title: 'Existing operational task');
    $before = $task->fresh()->getRawOriginal();
    expect(Artisan::call('ppm:demo', ['--owner' => $owner->id]))->toBe(0);
    expect(ProjectPortfolio::query()->count())->toBe(24)->and(ProjectClient::query()->count())->toBe(37)
        ->and(Project::query()->count())->toBe(78)->and(ProjectTask::query()->count())->toBe(2331)
        ->and($task->fresh()->getRawOriginal())->toBe($before)->and($task->assignments()->count())->toBe(1);
    foreach (Project::query()->where('code', 'like', 'PRJ-DEMO-%')->with('tasks')->get() as $sample) {
        foreach ($sample->tasks as $sampleTask) {
            expect(TaskSchedule::fitsWindow($sampleTask->start_date?->format('Y-m-d'), $sampleTask->due_date?->format('Y-m-d'), $sample->start_date->format('Y-m-d'), $sample->due_date?->format('Y-m-d')))->toBeTrue($sampleTask->code.' must fit its project window.');
        }
    }
    $project = Project::query()->findOrFail(PpmDemoRecords::id('project:1'));
    expect(app(UpdateProject::class)->handle(Access::forUser($owner), $project->id, new ProjectDraft(
        $project->name, $project->description ?? '', 'NONE', null, null, $project->due_date->format('Y-m-d'), (string) $project->manager_id,
    )))->toBe($project->code);
    $planner = User::query()->where('email', 'ppm-demo-2@example.test')->firstOrFail();
    $participant = User::query()->where('email', 'ppm-demo-5@example.test')->firstOrFail();
    $finance = app(ProjectFinancials::class);
    expect($finance->canView(Access::forUser($planner), $project->id))->toBeTrue()
        ->and($finance->canView(Access::forUser($participant), $project->id))->toBeFalse();
    expect(WorkScope::tasks(Access::forUser($participant))->where('project_id', $project->id)->count())->toBeGreaterThan(0)
        ->toBeLessThan($project->tasks()->count());
    $records = app(WorkRecords::class);
    $view = $records->task(Access::forUser($planner), PpmDemoRecords::id($project->id.':task:3'), $project->id);
    expect($view)->not->toBeNull();
    foreach (ProjectBudget::query()->where('status', 'APPROVED')->get() as $budget) {
        expect((int) ProjectBudgetLine::query()->where('budget_id', $budget->id)->sum('total_minor'))->toBe($budget->total_minor);
    }
    foreach (ProjectCashEntry::query()->where('allocation_revision', '>', 0)->get() as $entry) {
        expect((int) ProjectCashAllocation::query()->where('entry_id', $entry->id)->where('revision', $entry->allocation_revision)->sum('amount_minor'))->toBe($entry->amount_minor);
    }
    expect(ProjectReport::query()->distinct()->pluck('status')->sort()->values()->all())->toBe(['ACCEPTED', 'DRAFT', 'RETURNED', 'SUBMITTED']);
    foreach (ProjectReportSubmission::query()->get() as $submission) {
        expect(hash('sha256', $submission->payload))->toBe($submission->sha256);
        $data = json_decode($submission->payload, true, flags: JSON_THROW_ON_ERROR);
        expect($data['coverage']['blocking'])->toBe([])->and($submission->payload)->not->toContain('Private demo response');
    }
    foreach (ProjectRecordFile::query()->get() as $file) {
        expect(Storage::disk('local')->exists($file->path))->toBeTrue()
            ->and(hash('sha256', Storage::disk('local')->get($file->path)))->toBe($file->sha256);
    }
    expect(ProjectTaskFile::query()->where('mime', 'image/png')->count())->toBeGreaterThan(0);
    cpaLogin($owner);
    $capacity = Livewire::test(CapacityWorkspace::class, ['projectId' => $project->id]);
    $capacity->assertViewHas('people', fn ($people): bool => $people->total() > 20 && $people->count() === 20);
    $capacity->set('perPage', 50)->assertViewHas('people', fn ($people): bool => $people->count() === $people->total() && $people->perPage() === 50);
    $project->update(['name' => 'Edited demo project']);
    $changedTask = ProjectTask::query()->where('project_id', $project->id)->firstOrFail();
    $changedTask->update(['title' => 'Edited demo task']);
    $changedTask->syncAssignees([], null);
    $hashes = ppmDemoFingerprints();
    expect(Artisan::call('ppm:demo', ['--owner' => $owner->id]))->toBe(0);
    expect(ppmDemoFingerprints())->toBe($hashes);
});

it('refuses production and invalid owners without adding identities or work', function () {
    $owner = cpaUser(allData: true);
    expect(Artisan::call('ppm:demo', ['--owner' => 'invalid']))->toBe(1);
    expect(Artisan::call('ppm:demo', ['--owner' => 99999]))->toBe(1);
    app()->instance('env', 'production');
    expect(Artisan::call('ppm:demo', ['--owner' => $owner->id]))->toBe(1);
    expect(ProjectPortfolio::query()->count())->toBe(0)->and(User::query()->count())->toBe(1);
});

it('rolls back a reserved-identity collision instead of adopting or changing an existing account', function () {
    $owner = cpaUser(allData: true);
    $existing = cpaUser(cpaEmployee('Existing'));
    $existing->forceFill(['email' => 'ppm-demo-1@example.test'])->save();
    $before = $existing->fresh()->getRawOriginal();
    expect(Artisan::call('ppm:demo', ['--owner' => $owner->id]))->toBe(1);
    expect($existing->fresh()->getRawOriginal())->toBe($before)->and(ProjectPortfolio::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe(2);
});
