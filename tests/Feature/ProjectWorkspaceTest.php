<?php

declare(strict_types=1);

use App\Livewire\Projects\Index;
use App\Livewire\Projects\Show;
use App\Models\ProjectTask;
use App\Modules\WorkManagement\Application\Commands\ReviewTask;
use App\Modules\WorkManagement\Application\Queries\ProjectClients;
use App\Support\Access;
use Livewire\Livewire;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

it('opens the task form and saves unassigned work through the shared creation flow', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    cpaLogin($owner);
    Livewire::test(Show::class, ['project' => $project->id])->call('openTaskCreation')->assertSet('adding', true)
        ->set('taskTitle', '  Plan the launch  ')->call('saveTask')
        ->assertSet('taskTitle', '')->assertSet('reviewOpen', true)->assertViewHas('openTask', fn ($task): bool => $task?->title === 'Plan the launch');
    $task = ProjectTask::query()->sole();
    expect($task->project_id)->toBe($project->id)->and($task->status)->toBe('PENDING')
        ->and($task->assigneeIds())->toBe([])->and($task->due_date)->toBeNull();
});

it('keeps a refused task draft and writes nothing for a closed or unauthorized project', function (bool $closed) {
    $owner = cpaUser();
    $member = cpaUser();
    $client = cpaClient($owner, [$member]);
    $project = cpaProject($owner, $client);
    $project->update(['manager_id' => $member->id]);
    if ($closed) {
        $project->update(['status' => 'CANCELLED']);
    }
    cpaLogin($closed ? $owner : $member);
    $page = Livewire::test(Show::class, ['project' => $project->id]);
    $page->assertStatus(200);
    $page->assertSet('taskTitle', '')->assertViewHas('project', fn ($view): bool => $view?->id === $project->id);
    $page->set('taskTitle', 'Keep this draft')->call('openTaskCreation')->assertSet('adding', false)->call('saveTask')
        ->assertSet('taskTitle', 'Keep this draft')->assertSet('reviewOpen', false)->assertViewHas('canCreateTasks', false);
    expect(ProjectTask::query()->count())->toBe(0);
})->with(['leader only' => false, 'closed project' => true]);

it('keeps saved task properties visible to leaders and on closed tasks without granting edits', function (bool $closed) {
    $owner = cpaUser();
    $leader = cpaUser();
    $employee = cpaEmployee('Worker');
    $worker = cpaUser($employee, ['TASKS']);
    $client = cpaClient($owner, [$leader, $worker]);
    $project = cpaProject($owner, $client);
    $project->update(['manager_id' => $leader->id]);
    $task = cpaTask($owner, $project, [$employee]);
    $task->update(['priority' => 'URGENT']);
    if ($closed) {
        app(ReviewTask::class)->handle(Access::forUser($owner), $project->id, $task->id, 'CANCELLED', '');
    }
    cpaLogin($closed ? $owner : $leader);
    $page = Livewire::test(Show::class, ['project' => $project->id])->call('openTask', $task->id);
    $html = new DOMDocument;
    @$html->loadHTML($page->html());
    $xpath = new DOMXPath($html);
    $properties = '//*[@aria-label="Task properties"]';
    expect($xpath->evaluate('count('.$properties.')'))->toBe(1.0)
        ->and($xpath->evaluate('string('.$properties.')'))->toContain('Worker Member', 'Urgent', 'Nov 20, 2026')
        ->and($xpath->evaluate('count('.$properties.'//input[@aria-label="Due date"])'))->toBe(0.0)
        ->and($xpath->evaluate('count('.$properties.'//*[@aria-label="Task priority"])'))->toBe(0.0)
        ->and($xpath->evaluate('count('.$properties.'//input[@aria-label="Start date"])'))->toBe($closed ? 0.0 : 1.0);
})->with(['leader properties' => false, 'closed task properties' => true]);

it('clears search and task filters while retaining the selected client and library layout', function () {
    $owner = cpaUser();
    $client = cpaClient($owner);
    cpaLogin($owner);
    $page = Livewire::test(Index::class)->set('client', $client->id)->set('projectView', 'list')
        ->set('search', 'Launch')->set('status', 'ACTIVE')->set('queue', 'REVIEW')->call('clearFilters')
        ->assertSet('client', $client->id)->assertSet('projectView', 'list')->assertSet('search', '')->assertSet('status', 'ALL')->assertSet('queue', 'ALL');
    $page->set('projectView', 'unknown')->assertSet('projectView', 'cards');
});

it('counts only visible projects on client cards even when an unrelated client id is supplied', function () {
    $owner = cpaUser();
    $member = cpaUser();
    $other = cpaUser();
    $client = cpaClient($owner, [$member]);
    $hidden = cpaClient($other);
    cpaProject($owner, $client, 'Hidden from member');
    $visible = cpaProject($owner, $client, 'Led by member');
    $visible->update(['manager_id' => $member->id]);
    cpaProject($other, $hidden);
    $counts = app(ProjectClients::class)->projectCounts(Access::forUser($member), [$client->id, $hidden->id]);
    expect($counts)->toBe([$client->id => 1]);
    expect(app(ProjectClients::class)->projectCounts(Access::forUser($owner), [$client->id]))->toBe([$client->id => 2]);
});

it('seeds task details on open and lets the manager discard unsaved property edits', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $task = cpaTask($owner, $project, []);
    cpaLogin($owner);
    Livewire::test(Show::class, ['project' => $project->id])->call('openTask', $task->id)
        ->assertSet('draftPriority', 'NORMAL')->assertSet('draftDueDate', '2026-11-20')
        ->set('draftPriority', 'HIGH')->set('draftDueDate', '2026-11-25')->call('resetDetails')
        ->assertSet('draftPriority', 'NORMAL')->assertSet('draftDueDate', '2026-11-20');
    expect($task->fresh()->priority)->toBe('NORMAL');
});
