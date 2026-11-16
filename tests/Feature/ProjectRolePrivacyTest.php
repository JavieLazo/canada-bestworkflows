<?php

declare(strict_types=1);

use App\Livewire\Projects\Show;
use App\Models\ProjectTaskFile;
use App\Models\ProjectTaskSubmission;
use App\Modules\WorkManagement\Application\Commands\SetProjectMember;
use App\Modules\WorkManagement\Application\Queries\WorkRecords;
use App\Support\Access;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

it('keeps individual notes decisions and evidence private for project readers and editors', function (string $role) {
    Storage::fake('local');
    $owner = cpaUser();
    $reader = cpaUser();
    $first = cpaUser(cpaEmployee('First'), ['TASKS']);
    $second = cpaUser(cpaEmployee('Second'), ['TASKS']);
    $project = cpaProject($owner, cpaClient($owner, [$reader, $first, $second]));
    $task = cpaTask($owner, $project, [$first->employee, $second->employee], 'INDIVIDUAL');
    $task->update(['status' => 'IN_PROGRESS']);
    $response = new ProjectTaskSubmission;
    $response->forceFill(['task_id' => $task->id, 'employee_id' => $first->employee_id, 'status' => 'APPROVED', 'remarks' => 'Private response text', 'review_remarks' => 'Private reviewer text', 'submitted_at' => now(), 'reviewed_at' => now(), 'reviewed_by' => $owner->id, 'revision' => 1])->save();
    $files = [];
    foreach (['BRIEF', 'SUBMISSION'] as $kind) {
        $path = 'task-files/'.strtolower($kind).'.pdf';
        Storage::disk('local')->put($path, 'Synthetic PDF');
        $file = new ProjectTaskFile;
        $file->forceFill(['task_id' => $task->id, 'kind' => $kind, 'employee_id' => $kind === 'SUBMISSION' ? $first->employee_id : null, 'file_name' => strtolower($kind).'.pdf', 'path' => $path, 'mime' => 'application/pdf', 'size' => 13])->save();
        $files[$kind] = $file;
    }
    app(SetProjectMember::class)->handle(Access::forUser($owner), $project->id, $reader->id, $role);
    $actor = Access::forUser($reader);
    $records = app(WorkRecords::class);
    $view = $records->responses($actor, $task->id)->get($first->employee_id);
    expect($view->status)->toBe('APPROVED')->and($view->content_visible)->toBeFalse()
        ->and($view->toArray())->not->toHaveKeys(['id', 'remarks', 'review_remarks', 'reviewed_by', 'revision'])
        ->and($records->files($actor, [$task->id])->pluck('id')->all())->toBe([$files['BRIEF']->id]);
    cpaLogin($reader);
    Livewire::test(Show::class, ['project' => $project->id])->call('openTask', $task->id)
        ->assertDontSee('Private response text')->assertDontSee('Private reviewer text')->assertDontSee('submission.pdf');
    $this->get(route('projects.task-files.download', $files['BRIEF']->id))->assertOk();
    $this->get(route('projects.task-files.download', $files['SUBMISSION']->id))->assertForbidden();
})->with(['VIEWER', 'EDITOR']);
