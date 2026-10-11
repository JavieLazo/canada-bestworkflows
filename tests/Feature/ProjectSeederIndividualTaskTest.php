<?php

declare(strict_types=1);

use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskAssignee;
use App\Models\ProjectTaskSubmission;
use App\Models\TaskComment;
use App\Models\User;
use App\Modules\WorkManagement\Application\Commands\CreateTask;
use App\Modules\WorkManagement\Application\Commands\ReviewTaskResponse;
use App\Modules\WorkManagement\Application\Commands\SubmitTask;
use App\Modules\WorkManagement\Application\TaskDraft;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Support\Access;
use Database\Seeders\EmployeeSeeder;
use Database\Seeders\ProjectSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;

/**
 * The demo data carries exactly one INDIVIDUAL task, TSK-013, in an active
 * project: three assignees, one of them the demo Employee account's employee
 * with no response yet, one APPROVED and one RETURNED response, and a crew
 * that leaves both all-data reviewers (admin, supervisor) free to decide it.
 * Running ProjectSeeder again changes nothing, a re-run after a walkthrough
 * restores that demo state, and a TSK-013 the seeder did not create (the next
 * code CreateTask mints on a database seeded before it) is never touched.
 */
function seedindRun(): void
{
    test()->seed([RoleSeeder::class, EmployeeSeeder::class, ProjectSeeder::class]);
}

function seedindTask(): ProjectTask
{
    return ProjectTask::query()->where('code', 'TSK-013')->firstOrFail();
}

function seedindEmployeeOf(string $email): string
{
    return (string) User::query()->where('email', $email)->value('employee_id');
}

function seedindAs(string $email): Access
{
    app()->forgetInstance(Access::class);

    return Access::forActor(User::query()->where('email', $email)->firstOrFail());
}

/** @return array{task: array<string, mixed>, assignees: list<array<string, mixed>>, responses: list<array<string, mixed>>} */
function seedindTaskRows(string $taskId): array
{
    $rows = static fn (string $model): array => array_values(array_map(
        static fn (Model $row): array => $row->getAttributes(),
        $model::query()->where('task_id', $taskId)->orderBy('id')->get()->all(),
    ));

    return [
        'task' => ProjectTask::query()->whereKey($taskId)->firstOrFail()->getAttributes(),
        'assignees' => $rows(ProjectTaskAssignee::class),
        'responses' => $rows(ProjectTaskSubmission::class),
    ];
}

/**
 * @param  class-string<Model>  $model
 * @return list<array<string, mixed>> the table's raw rows, by id
 */
function seedindRows(string $model): array
{
    $rows = [];

    foreach ($model::query()->orderBy('id')->get() as $row) {
        $rows[] = $row->getAttributes();
    }

    return $rows;
}

/** @return array<string, list<array<string, mixed>>> every table ProjectSeeder writes, row by row */
function seedindSnapshot(): array
{
    return [
        'projects' => seedindRows(Project::class),
        'tasks' => seedindRows(ProjectTask::class),
        'assignees' => seedindRows(ProjectTaskAssignee::class),
        'responses' => seedindRows(ProjectTaskSubmission::class),
        'comments' => seedindRows(TaskComment::class),
        'notifications' => seedindRows(Notification::class),
    ];
}

/*
|--------------------------------------------------------------------------
| The seeded task
|--------------------------------------------------------------------------
*/

it('seeds one individual task with an approved, a returned and a missing response', function () {
    seedindRun();

    $task = seedindTask();
    $demoEmployee = seedindEmployeeOf('employee@bestworkflows.local');

    expect($task->submission_mode)->toBe('INDIVIDUAL')
        ->and($task->status)->toBe('IN_PROGRESS')
        ->and(Project::query()->whereKey($task->project_id)->value('status'))->toBe('ACTIVE')
        // Nothing of a response lives on an INDIVIDUAL task's own row.
        ->and($task->submitted_by)->toBeNull()
        ->and($task->remarks)->toBeNull()
        ->and($task->reviewed_by)->toBeNull()
        ->and(ProjectTask::query()->where('submission_mode', 'INDIVIDUAL')->count())->toBe(1);

    $crew = $task->assigneeIds();

    expect($crew)->toHaveCount(3)
        ->and($demoEmployee)->not->toBe('')
        ->and($crew)->toContain($demoEmployee);

    $responses = ProjectTaskSubmission::query()->where('task_id', $task->id)->get();

    expect($responses)->toHaveCount(2)
        ->and($responses->pluck('status')->sort()->values()->all())->toBe(['APPROVED', 'RETURNED'])
        // Rows are a subset of the crew, and the demo employee has none.
        ->and(array_diff($responses->pluck('employee_id')->all(), $crew))->toBe([])
        ->and($responses->pluck('employee_id')->all())->not->toContain($demoEmployee);

    foreach ($responses as $response) {
        expect($response->reviewed_by)->toBe((int) $task->assigned_by)
            ->and($response->reviewed_at)->not->toBeNull()
            ->and($response->submitted_at->lessThan($response->reviewed_at))->toBeTrue()
            ->and($response->submitted_at->greaterThan($task->assigned_at))->toBeTrue()
            ->and($response->revision)->toBe(2);
    }

    expect($responses->firstWhere('status', 'RETURNED')?->review_remarks)->not->toBeEmpty();
});

it('leaves the individual task to reviewers who are not on its crew', function () {
    seedindRun();

    $task = seedindTask();
    $supervisor = User::query()->where('email', 'supervisor@bestworkflows.local')->firstOrFail();

    expect((int) $task->assigned_by)->toBe((int) $supervisor->id)
        ->and($task->hasAssignee(seedindEmployeeOf('supervisor@bestworkflows.local')))->toBeFalse()
        ->and($task->hasAssignee(seedindEmployeeOf('admin@bestworkflows.local')))->toBeFalse();

    foreach (['supervisor@bestworkflows.local', 'admin@bestworkflows.local'] as $email) {
        $access = Access::forActor(User::query()->where('email', $email)->firstOrFail());

        expect(WorkRules::reviewsTask($access, $task))->toBeTrue();
    }

    // The demo employee submits it, and so never reviews it.
    $employee = Access::forActor(User::query()->where('email', 'employee@bestworkflows.local')->firstOrFail());

    expect($task->hasAssignee($employee->employeeId))->toBeTrue()
        ->and(WorkRules::reviewsTask($employee, $task))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Re-running the seeder
|--------------------------------------------------------------------------
*/

it('changes nothing when it runs a second time', function () {
    // The seeded instants are Manila days back from now: hold the clock so a
    // run straddling midnight cannot tell the two runs apart.
    $this->travelTo(new DateTimeImmutable('2026-08-20T12:00:00+08:00'));

    seedindRun();
    $before = seedindSnapshot();

    $this->seed(ProjectSeeder::class);

    expect(seedindSnapshot())->toBe($before)
        ->and($before['responses'])->toHaveCount(2)
        ->and(ProjectTaskAssignee::query()->where('task_id', seedindTask()->id)->count())->toBe(3);
});

it('gives the demo employee back an unanswered response when it runs again after a walkthrough', function () {
    seedindRun();

    $task = seedindTask();
    $demoEmployee = seedindEmployeeOf('employee@bestworkflows.local');
    $seededResponses = ProjectTaskSubmission::query()->where('task_id', $task->id)->where('employee_id', '!=', $demoEmployee)->orderBy('employee_id')->pluck('status', 'employee_id')->all();

    // The walkthrough the class docblock describes: emp-4 hands in from My
    // Tasks and a reviewer approves it; emp-6 (no login) is then taken off
    // the crew so the task can complete.
    expect(app(SubmitTask::class)->handle(seedindAs('employee@bestworkflows.local'), $task->id, 'Refresher finished.'))->toBeTrue();

    $mine = ProjectTaskSubmission::query()->where('task_id', $task->id)->where('employee_id', '=', $demoEmployee)->firstOrFail();
    app(ReviewTaskResponse::class)->handle(seedindAs('supervisor@bestworkflows.local'), $task->project_id, $task->id, $mine->id, 'APPROVED', '', $mine->revision);

    $approved = (string) array_search('APPROVED', $seededResponses, true);
    $task->syncAssignees([$demoEmployee, $approved], (int) $task->assigned_by);
    $task->forceFill(['status' => 'COMPLETED', 'completed_at' => now()])->save();

    expect(ProjectTaskSubmission::query()->whereKey($mine->id)->value('status'))->toBe('APPROVED');

    $this->seed(ProjectSeeder::class);

    $task = seedindTask();

    expect($task->status)->toBe('IN_PROGRESS')
        ->and($task->completed_at)->toBeNull()
        ->and($task->assigneeIds())->toHaveCount(3)
        ->and(ProjectTaskSubmission::query()->where('task_id', $task->id)->where('employee_id', '=', $demoEmployee)->exists())->toBeFalse()
        ->and(ProjectTaskSubmission::query()->where('task_id', $task->id)->orderBy('employee_id')->pluck('status', 'employee_id')->all())->toBe($seededResponses);

    // ...so the walkthrough can start again.
    expect(app(SubmitTask::class)->handle(seedindAs('employee@bestworkflows.local'), $task->id, 'Refresher finished again.'))->toBeTrue()
        ->and(ProjectTaskSubmission::query()->where('task_id', $task->id)->where('employee_id', '=', $demoEmployee)->value('status'))->toBe('SUBMITTED');
});

it('never takes over a TSK-013 that someone created before the demo task existed', function () {
    seedindRun();

    // A database seeded before TSK-013 was added: the twelve reference tasks
    // and nothing else.
    $seeded = seedindTask();
    ProjectTaskSubmission::query()->where('task_id', $seeded->id)->delete();
    ProjectTaskAssignee::query()->where('task_id', $seeded->id)->delete();
    $seeded->delete();

    // The first task created there is handed the next code: TSK-013.
    $crew = [seedindEmployeeOf('employee@bestworkflows.local'), seedindEmployeeOf('admin@bestworkflows.local')];
    $created = app(CreateTask::class)->handle(seedindAs('supervisor@bestworkflows.local'), seededId(Project::class, 'prj-1'), new TaskDraft('Count the fire extinguishers', 'Every floor, with the expiry dates.', 'NORMAL', '', $crew));

    expect($created?->code)->toBe('TSK-013')
        ->and($created?->id)->not->toBe($seeded->id);

    $before = seedindTaskRows((string) $created?->id);

    expect(Artisan::call('db:seed', ['--class' => ProjectSeeder::class, '--no-interaction' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('TSK-013 already belongs to another task');

    expect(seedindTaskRows((string) $created?->id))->toBe($before)
        ->and(ProjectTask::query()->whereKey($seeded->id)->exists())->toBeFalse()
        ->and(ProjectTask::query()->where('code', 'TSK-013')->count())->toBe(1)
        ->and(ProjectTask::query()->where('submission_mode', 'INDIVIDUAL')->exists())->toBeFalse();
});
