<?php

declare(strict_types=1);

use App\Livewire\Projects\Show;
use App\Modules\WorkManagement\Application\Commands\UpdateTaskDetails;
use App\Modules\WorkManagement\Application\TaskDateEdit;
use App\Support\Access;
use Livewire\Livewire;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

it('uses the same scoped rows and date editor from the project Gantt view', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $task = cpaTask($owner, $project, [], title: 'Schedule delivery');
    cpaLogin($owner);
    $page = Livewire::test(Show::class, ['project' => $project->id])->set('taskView', 'gantt')->assertSee('Task timeline');
    $page->call('openSchedule', $task->id)->assertSet('scheduling', true)->set('scheduleStart', '2026-11-10')->set('scheduleDue', '2026-11-22')->call('saveSchedule')->assertSet('scheduling', false);
    expect($task->fresh()->due_date->format('Y-m-d'))->toBe('2026-11-22');
    $page->set('search', 'no-match')->call('showOnTimeline', $task->id)->assertViewHas('visibleCount', 0);
});

it('merges untouched form dates and refuses an edited date whose version changed', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $task = cpaTask($owner, $project, []);
    $actor = Access::forUser($owner);
    cpaLogin($owner);
    $page = Livewire::test(Show::class, ['project' => $project->id])->call('openTask', $task->id);
    app(UpdateTaskDetails::class)->handle($actor, $project->id, $task->id, new TaskDateEdit('', '2026-11-22', '', '2026-11-20'));
    $page->set('draftPriority', 'HIGH')->call('saveDetails')->assertSet('draftDueDate', '2026-11-22');
    expect($task->fresh()->priority)->toBe('HIGH')->and($task->fresh()->due_date->format('Y-m-d'))->toBe('2026-11-22');
    app(UpdateTaskDetails::class)->handle($actor, $project->id, $task->id, new TaskDateEdit('2026-11-12', '2026-11-22', '', '2026-11-22'));
    $page->set('draftStartDate', '2026-11-11')->call('saveDetails')->assertSet('draftStartDate', '2026-11-11');
    expect($task->fresh()->start_date->format('Y-m-d'))->toBe('2026-11-12');
});
